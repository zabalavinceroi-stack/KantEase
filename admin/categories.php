<?php

declare(strict_types=1);

/**
 * KantEase — administrator category management.
 *
 *     GET  ?q=&status=&page=            list, filtered
 *     GET  ?edit=<id>                   show the edit form for one category
 *     POST action=create                 add a category
 *     POST action=update                 rename / reorder / activate
 *     POST action=activate|deactivate    one-row toggle from the list
 *     POST action=delete                 delete, refused while products exist
 *
 * ADMINISTRATORS ONLY. Router::requirePanel(UserRole::Admin) is the first
 * statement after the bootstrap, so an anonymous visitor is sent to sign-in and
 * a student is sent to their own dashboard before any field is read. Every
 * mutation additionally calls Csrf::verifyRequest() before doing anything.
 *
 * Category management is where a menu is built, so it is deliberately small
 * and hard to get wrong:
 *
 *   - Names are unique. The database has a UNIQUE key and this page reports the
 *     collision under the name field rather than letting it surface as a 500.
 *   - A category holding products cannot be deleted. "Snacks" with 30 products
 *     under it is not a stray row.
 *   - Switching a category off hides its products from students but deletes
 *     nothing. The page says how many products that affects before it happens.
 *   - New categories are appended to the list rather than pushed to the top, so
 *     adding one never silently reorders the menu the canteen already relies on.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

use KantEase\BusinessRuleException;
use KantEase\Csrf;
use KantEase\Database;
use KantEase\Layout;
use KantEase\NotFoundException;
use KantEase\Pagination;
use KantEase\Repositories\CategoryRepository;
use KantEase\Router;
use KantEase\UserRole;
use KantEase\ValidationException;
use KantEase\Validator;

use function KantEase\e;
use function KantEase\field_errors_set;
use function KantEase\field_errors_take;
use function KantEase\flash_set;
use function KantEase\forget_old_input;
use function KantEase\format_date;
use function KantEase\input_int;
use function KantEase\input_string;
use function KantEase\is_post;
use function KantEase\old;
use function KantEase\query_int;
use function KantEase\query_string;
use function KantEase\redirect;
use function KantEase\remember_old_input;
use function KantEase\url;
use function KantEase\with_query;

$user    = Router::requirePanel(UserRole::Admin);
$actorId = (int) $user['id'];

$pageUrl = '/admin/categories.php';

// ---------------------------------------------------------------------------
// Submit
// ---------------------------------------------------------------------------

if (is_post()) {
    Csrf::verifyRequest();

    $action = input_string('action');
    $id     = input_int('id');

    // Everything below needs a category to exist. Resolving it up front means a
    // stale id in a replayed form produces one clear message rather than a
    // different failure in each branch.
    $category = $id > 0 ? CategoryRepository::findById($id) : null;

    if ($id > 0 && $category === null) {
        flash_set('error', 'That category no longer exists.');
        redirect($pageUrl);
    }

    try {
        switch ($action) {
            case 'create':
            case 'update':
                $name       = input_string('name');
                $isActive   = input_string('is_active') === '1';
                $sortOrder  = input_int('sort_order', 0);

                remember_old_input([
                    'name'       => $name,
                    'is_active'  => $isActive ? '1' : '0',
                    'sort_order' => (string) $sortOrder,
                ]);

                $validator = Validator::for($_POST)
                    ->text('name', 'Category name', 1, CategoryRepository::NAME_MAX);

                if ($sortOrder < 0 || $sortOrder > 9999) {
                    $validator->reject('sort_order', 'Order must be between 0 and 9999.');
                }

                $clean = $validator->validate();

                $result = $action === 'create'
                    ? CategoryRepository::createAs(
                        (string) $clean['name'],
                        $isActive,
                        $actorId
                    )
                    : CategoryRepository::updateAs(
                        $id,
                        (string) $clean['name'],
                        $isActive,
                        $sortOrder,
                        $actorId
                    );

                if ($result === null) {
                    // The name is already used. Reported per field, which is
                    // more useful than a general failure banner.
                    field_errors_set([
                        'name' => 'There is already a category called "'
                            . (string) $clean['name'] . '".',
                    ]);

                    flash_set('error', 'That category name is already taken.');

                    redirect($action === 'create'
                        ? $pageUrl
                        : $pageUrl . with_query(['edit' => $id]));
                }

                forget_old_input();

                flash_set(
                    'success',
                    $action === 'create'
                        ? 'Category "' . (string) $result['name'] . '" was added.'
                        : 'Category "' . (string) $result['name'] . '" was saved.'
                );

                redirect($action === 'create'
                    ? $pageUrl
                    : $pageUrl . with_query(['edit' => $id]));

            case 'activate':
            case 'deactivate':
                $updated = CategoryRepository::setActive(
                    $id,
                    $action === 'activate',
                    $actorId
                );

                // How many products this actually hides. Reported so "hidden"
                // is never mistaken for "gone".
                $hidden = (int) Database::fetchValue(
                    'SELECT COUNT(*) FROM food_items
                      WHERE category_id = ? AND is_archived = 0 AND is_available = 1',
                    [$id]
                );

                if ($action === 'activate') {
                    flash_set('success', sprintf(
                        '"%s" is on the menu again.',
                        (string) $updated['name']
                    ));
                } else {
                    flash_set(
                        'success',
                        sprintf(
                            '"%s" is hidden from students. %s',
                            (string) $updated['name'],
                            $hidden === 0
                                ? 'It has no products on the menu.'
                                : sprintf(
                                    '%d product%s will no longer be shown. Nothing was deleted.',
                                    $hidden,
                                    $hidden === 1 ? '' : 's'
                                )
                        )
                    );
                }

                redirect($pageUrl . with_query(list_query()));

            case 'delete':
                $name = (string) ($category['name'] ?? '');

                $deleted = CategoryRepository::deleteAs($id, $actorId);

                if (! $deleted) {
                    flash_set(
                        'error',
                        sprintf(
                            '"%s" still has products in it. Move or delete them first — a category cannot be deleted while products point at it.',
                            $name
                        )
                    );

                    redirect($pageUrl . with_query(['edit' => $id]));
                }

                flash_set('success', sprintf('Category "%s" was deleted.', $name));
                redirect($pageUrl . with_query(list_query()));

            default:
                // An unrecognised action is refused rather than ignored: a
                // typo in a form must never fall through to "do nothing" and
                // report success.
                throw new ValidationException(
                    ['action' => 'That request could not be understood. Please reload the page.'],
                    'That request could not be understood. Please reload the page.'
                );
        }
    } catch (ValidationException $exception) {
        field_errors_set($exception->errors());
        flash_set('error', $exception->getMessage());

        redirect($action === 'create'
            ? $pageUrl
            : $pageUrl . with_query(['edit' => $id]));
    } catch (BusinessRuleException | NotFoundException $exception) {
        flash_set('error', $exception->getMessage());
        redirect($pageUrl);
    }
}

/**
 * The list filters currently in force, for preserving links.
 *
 * @return array<string, string>
 */
function list_query(): array
{
    $query = [
        'q'      => query_string('q'),
        'status' => query_string('status'),
    ];

    return array_filter($query, static fn (string $value): bool => $value !== '' && $value !== 'all');
}

// ---------------------------------------------------------------------------
// Read
// ---------------------------------------------------------------------------

$editId  = query_int('edit');
$editing = $editId > 0 ? CategoryRepository::findById($editId) : null;

if ($editId > 0 && $editing === null) {
    flash_set('error', 'That category no longer exists.');
    redirect($pageUrl);
}

$counts = CategoryRepository::counts();

try {
    $listing = CategoryRepository::paginate(
        CategoryRepository::categorySearch(),
        query_string('status', 'all'),
        query_int('page', 1)
    );
} catch (ValidationException $exception) {
    // A malformed ?q= is a search box being typed into, not an attack. Say so
    // and show the unfiltered list rather than serving an error page.
    flash_set('error', $exception->getMessage());
    redirect($pageUrl);
}

/** @var list<array<string, mixed>> $categories */
$categories = $listing['rows'];

/** @var Pagination $pager */
$pager = $listing['pagination'];

$errors = field_errors_take();

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------

Layout::appStart([
    'title'    => 'Categories',
    'heading'  => 'Categories',
    'subtitle' => 'Group the canteen menu. A hidden category keeps its products but takes them off the student menu.',
    'actions'  => '<a class="btn" href="' . e(url('/admin/food.php')) . '">Manage food</a>',
]);

// -- Header figures ----------------------------------------------------------

echo '    <div class="stat-grid">' . "\n";

echo '      <div class="stat">' . "\n";
echo '        <span class="stat__body">' . "\n";
echo '          <span class="stat__label">Categories</span>' . "\n";
echo '          <span class="stat__value tnum">' . e((string) $counts['total']) . "</span>\n";
echo '          <span class="stat__hint">' . e((string) $counts['active']) . ' on the menu</span>' . "\n";
echo "        </span>\n";
echo "      </div>\n";

echo '      <div class="stat">' . "\n";
echo '        <span class="stat__body">' . "\n";
echo '          <span class="stat__label">Hidden</span>' . "\n";
echo '          <span class="stat__value tnum">'
    . e((string) max(0, $counts['total'] - $counts['active'])) . "</span>\n";
echo '          <span class="stat__hint">Products stay, students stop seeing them</span>' . "\n";
echo "        </span>\n";
echo "      </div>\n";

echo "    </div>\n";

// -- Add / edit form ---------------------------------------------------------

$isEdit  = $editing !== null;
$formFor = $pageUrl . ($isEdit ? with_query(['edit' => $editId]) : '');

echo '    <div class="card mt-6">' . "\n";
echo '      <div class="card__header">' . "\n";
echo '        <h2 class="card__title">' . ($isEdit ? 'Edit category' : 'Add a category') . "</h2>\n";
echo "      </div>\n";
echo '      <div class="card__body">' . "\n";

echo '        <form method="post" action="' . e($formFor) . '" novalidate>' . "\n";
echo '          ' . Csrf::field() . "\n";
echo '          <input type="hidden" name="action" value="' . ($isEdit ? 'update' : 'create') . '">' . "\n";

if ($isEdit) {
    echo '          <input type="hidden" name="id" value="' . (int) $editing['id'] . '">' . "\n";
}

echo '          <div class="form-grid">' . "\n";

Layout::field('name', 'Category name', $errors, [
    'maxlength' => CategoryRepository::NAME_MAX,
    'autofocus' => true,
    'value'     => $isEdit
        ? (string) $editing['name']
        : (string) old('name', ''),
    'hint'      => 'For example Meals, Snacks, Drinks.',
]);

Layout::field('sort_order', 'Order', $errors, [
    'type'      => 'number',
    'min'       => 0,
    'max'       => 9999,
    'value'     => (string) ($isEdit
        ? (int) $editing['sort_order']
        : (int) old('sort_order', '0')),
    'hint'      => 'Lower numbers appear first on the student menu.',
    'required'  => false,
]);

echo '            <div class="field">' . "\n";
echo '              <label class="check" for="field-is_active">' . "\n";
echo '                <input type="checkbox" id="field-is_active" name="is_active" value="1"'
    . (($isEdit ? (int) $editing['is_active'] === 1 : (string) old('is_active', '1') === '1') ? ' checked' : '')
    . '>' . "\n";
echo '                <span>Show this category on the student menu</span>' . "\n";
echo "              </label>\n";
echo "            </div>\n";

echo "          </div>\n";

echo '          <div class="form-actions">' . "\n";
echo '            <button class="btn" type="submit" data-submit>'
    . ($isEdit ? 'Save changes' : 'Add category') . "</button>\n";

if ($isEdit) {
    echo '            <a class="btn btn--ghost" href="' . e($pageUrl) . '">Cancel</a>' . "\n";
}

echo "          </div>\n";
echo "        </form>\n";
echo "      </div>\n";
echo "    </div>\n";

// -- Filters -----------------------------------------------------------------

$statusFilter = query_string('status', 'all');

if (! in_array($statusFilter, ['all', 'active', 'inactive'], true)) {
    $statusFilter = 'all';
}

$keep = list_query();

/**
 * The status dropdown, rendered as a filter field.
 */
$statusOptions = '';

foreach (['all' => 'All', 'active' => 'On the menu', 'inactive' => 'Hidden'] as $value => $label) {
    $statusOptions .= '  <div class="field field--narrow">' . "\n"
        . '    <label class="field__label" for="filter-status">Show</label>' . "\n"
        . '    <select id="filter-status" name="status">' . "\n";

    foreach (['all' => 'All', 'active' => 'On the menu', 'inactive' => 'Hidden'] as $optionValue => $optionLabel) {
        $statusOptions .= '      <option value="' . e((string) $optionValue) . '"'
            . ($optionValue === $statusFilter ? ' selected' : '') . '>'
            . e($optionLabel) . "</option>\n";
    }

    $statusOptions .= "    </select>\n"
        . "  </div>\n";
}

echo '    <div class="card mt-6">' . "\n";

Layout::filterBar($pageUrl, [$statusOptions], $keep);

if ($keep !== []) {
    echo '  <p class="filter-summary">'
        . 'Showing a filtered list. <a href="' . e(url($pageUrl)) . '">Clear filters</a>.</p>' . "\n";
}

// -- Table -------------------------------------------------------------------

echo '      <div class="table-wrap">' . "\n";
echo '        <table class="table table--responsive">' . "\n";
echo '          <caption class="visually-hidden">Menu categories</caption>' . "\n";
echo '          <thead>' . "\n";
echo '            <tr>' . "\n";
echo '              <th scope="col">Name</th>' . "\n";
echo '              <th scope="col" class="is-numeric">Products</th>' . "\n";
echo '              <th scope="col">On menu</th>' . "\n";
echo '              <th scope="col" class="is-numeric">Order</th>' . "\n";
echo '              <th scope="col">Added</th>' . "\n";
echo '              <th scope="col">Actions</th>' . "\n";
echo "            </tr>\n";
echo "          </thead>\n";
echo "          <tbody>\n";

if ($categories === []) {
    echo '            <tr><td class="table__empty" colspan="6">'
        . ($keep === []
            ? 'No categories yet. Add the first one above, then start adding food.'
            : 'No categories match this filter.')
        . "</td></tr>\n";
}

foreach ($categories as $category) {
    $categoryId  = (int) $category['id'];
    $isActive    = (int) $category['is_active'] === 1;
    $itemCount   = (int) $category['item_count'];
    $menuCount   = (int) ($category['menu_count'] ?? 0);

    echo '            <tr' . ($categoryId === $editId ? ' style="background:var(--surface-2)"' : '') . '>' . "\n";

    echo '              <td data-label="Name">' . "\n";
    echo '                <span class="text-strong">' . e((string) $category['name']) . "</span>\n";
    echo '                <span class="stat__hint mono">#' . $categoryId . "</span>\n";
    echo "              </td>\n";

    echo '              <td data-label="Products" class="is-numeric">' . $itemCount . "\n";

    if ($itemCount > $menuCount) {
        // Worth saying: 6 products but only 2 on the menu means staff are being
        // surprised by what students can actually buy.
        echo '                <span class="stat__hint">' . $menuCount . ' on menu</span>' . "\n";
    }

    echo "              </td>\n";

    echo '              <td data-label="On menu">' . "\n";
    echo '                <span class="badge ' . ($isActive ? 'badge--ready' : 'badge--completed') . '">'
        . ($isActive ? 'Shown' : 'Hidden') . "</span>\n";
    echo "              </td>\n";

    echo '              <td data-label="Order" class="is-numeric">' . (int) $category['sort_order'] . "</td>\n";
    echo '              <td data-label="Added" class="nowrap">' . e(format_date((string) $category['created_at'])) . "</td>\n";

    echo '              <td data-label="Actions">' . "\n";
    echo '                <div class="flow-right">' . "\n";

    // Edit. A link, not a button: this page is fully usable with JS disabled,
    // and the toggle below degrades to the same place.
    echo '                  <a class="btn btn--sm btn--quiet" href="'
        . e($pageUrl . with_query(['edit' => $categoryId])) . '">Edit</a>' . "\n";

    // Availability toggle. A real form with a CSRF token, so the action is
    // authorised server-side rather than by the presence of a button.
    echo '                  <form method="post" action="' . e($pageUrl) . '">' . "\n";
    echo '                    ' . Csrf::field() . "\n";
    echo '                    <input type="hidden" name="action" value="'
        . ($isActive ? 'deactivate' : 'activate') . '">' . "\n";
    echo '                    <input type="hidden" name="id" value="' . $categoryId . '">' . "\n";
    echo '                    <button class="btn btn--sm" type="submit">'
        . ($isActive ? 'Hide' : 'Show') . "</button>\n";
    echo "                  </form>\n";

    // Delete. Only offered when there is genuinely nothing under it, so the
    // interface does not present an action the server will always refuse.
    if ($itemCount === 0) {
        echo '                  <form method="post" action="' . e($pageUrl) . '"'
            . ' data-confirm-danger'
            . ' data-confirm="Delete the category “' . e((string) $category['name']) . '”?'
            . ' This cannot be undone.">' . "\n";
        echo '                    ' . Csrf::field() . "\n";
        echo '                    <input type="hidden" name="action" value="delete">' . "\n";
        echo '                    <input type="hidden" name="id" value="' . $categoryId . '">' . "\n";
        echo '                    <button class="btn btn--sm btn--danger" type="submit">Delete</button>' . "\n";
        echo "                  </form>\n";
    } else {
        echo '                  <span class="stat__hint">'
            . 'Move its ' . $itemCount . ' product' . ($itemCount === 1 ? '' : 's') . ' first</span>' . "\n";
    }

    echo "                </div>\n";
    echo "              </td>\n";
    echo "            </tr>\n";
}

echo "          </tbody>\n";
echo "        </table>\n";
echo "      </div>\n";

Layout::pagination($pager, $pageUrl, $keep);

echo "    </div>\n";

Layout::appEnd();