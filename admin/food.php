<?php

declare(strict_types=1);

/**
 * KantEase — administrator food management.
 *
 *     GET  ?q=&category=&status=&archived=&page=   list, filtered
 *     GET  ?edit=<id>                              show the edit form
 *     POST action=create                           add a product
 *     POST action=update                           save a product
 *     POST action=activate|deactivate              one-row availability toggle
 *     POST action=archive|restore                  remove from / return to the menu
 *     POST action=remove_image                     drop the photograph
 *
 * ADMINISTRATORS ONLY. Router::requirePanel(UserRole::Admin) runs before any
 * field is read, and every mutation verifies the CSRF token before acting.
 *
 * Stock is deliberately NOT a free-text field here.
 *
 * `stock` on create is the opening count, written once. After that, stock moves
 * only through Admin -> Inventory, which is where the ledger lives: every change
 * records who did it, why, and the count on either side. Making it editable here
 * as a plain overwrite would create a second, unaudited path to the same number
 * and two screens that disagree about who is allowed to change it.
 *
 * The edit form still shows the current stock, read-only, with a link to the
 * Inventory screen. That is the honest arrangement: one place owns the number.
 *
 * Availability (available / sold out today) IS editable here, because it is a
 * daily operational decision, not a stock count.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

use KantEase\BusinessRuleException;
use KantEase\Config;
use KantEase\Csrf;
use KantEase\ImageUpload;
use KantEase\Layout;
use KantEase\MovementType;
use KantEase\NotFoundException;
use KantEase\Pagination;
use KantEase\Repositories\CategoryRepository;
use KantEase\Repositories\FoodRepository;
use KantEase\Router;
use KantEase\UserRole;
use KantEase\ValidationException;
use KantEase\Validator;

use function KantEase\cents_to_amount;
use function KantEase\e;
use function KantEase\field_errors_set;
use function KantEase\field_errors_take;
use function KantEase\flash_set;
use function KantEase\forget_old_input;
use function KantEase\format_date;
use function KantEase\format_datetime;
use function KantEase\input_int;
use function KantEase\input_string;
use function KantEase\is_post;
use function KantEase\old;
use function KantEase\peso;
use function KantEase\query_int;
use function KantEase\query_string;
use function KantEase\redirect;
use function KantEase\remember_old_input;
use function KantEase\to_cents;
use function KantEase\url;
use function KantEase\with_query;

$user    = Router::requirePanel(UserRole::Admin);
$actorId = (int) $user['id'];

$pageUrl = '/admin/food.php';

/** Longest product name, matching the column. */
const NAME_MAX = 120;

/** Longest description, matching the column. */
const DESCRIPTION_MAX = 255;

/**
 * Human-readable byte size, for the upload hint.
 */
function human_bytes(int $bytes): string
{
    if ($bytes >= 1_048_576) {
        return number_format($bytes / 1_048_576, 1) . ' MB';
    }

    return number_format($bytes / 1024, 0) . ' KB';
}

/**
 * The filters currently in force, so every link preserves them.
 *
 * @return array<string, string>
 */
function list_query(): array
{
    return array_filter([
        'q'        => query_string('q'),
        'category' => query_string('category'),
        'status'   => query_string('status'),
        'archived' => query_string('archived'),
    ], static fn (string $value): bool => $value !== '' && $value !== 'all');
}

// ---------------------------------------------------------------------------
// Submit
// ---------------------------------------------------------------------------

if (is_post()) {
    Csrf::verifyRequest();

    $action = input_string('action');
    $id     = input_int('id');

    $product = $id > 0 ? FoodRepository::findById($id) : null;

    if ($id > 0 && $product === null) {
        flash_set('error', 'That product no longer exists.');
        redirect($pageUrl);
    }

    $backToForm = $action === 'create'
        ? $pageUrl
        : $pageUrl . with_query(['edit' => $id]);

    try {
        switch ($action) {
            case 'create':
            case 'update':
                $name        = input_string('name');
                $description = input_string('description');
                $priceText   = input_string('price');
                $categoryId  = input_int('category_id');
                $isAvailable = input_string('is_available') === '1';
                $lowStock    = input_int('low_stock_level', 5);

                // Only meaningful on create: the opening stock.
                $openingStock = input_int('stock', 0);

                remember_old_input([
                    'name'            => $name,
                    'description'     => $description,
                    'price'           => $priceText,
                    'category_id'     => (string) $categoryId,
                    'is_available'    => $isAvailable ? '1' : '0',
                    'low_stock_level' => (string) $lowStock,
                    'stock'           => (string) $openingStock,
                ]);

                $validator = Validator::for($_POST)
                    ->text('name', 'Product name', 1, NAME_MAX)
                    ->optionalNote('description', 'Description', DESCRIPTION_MAX)
                    ->money('price', 'Price', 1, 9_999_999_999)
                    ->integer('category_id', 'Category', 1)
                    ->integer('low_stock_level', 'Low-stock alert level', 0, 100_000);

                if ($action === 'create') {
                    $validator->integer('stock', 'Opening stock', 0, 1_000_000);
                }

                $clean = $validator->validate();

                // Category existence and product-name uniqueness are both
                // business rules, not field shapes, so they live in the
                // repository layer where any future caller gets them too.
                FoodRepository::assertCategoryExists((int) $clean['category_id']);
                FoodRepository::assertNameFree((string) $clean['name'], $action === 'update' ? $id : null);

                // -- Photograph ------------------------------------------------
                //
                // The file is validated and moved BEFORE the row is written.
                // If the database then refuses the insert, delete() removes the
                // orphan, so a failed save never leaves a file behind.
                $upload = ImageUpload::receive('photo');

                if (! $upload->uploaded && $upload->error !== null) {
                    field_errors_set(['photo' => $upload->error]);
                    flash_set('error', $upload->error);
                    redirect($backToForm);
                }

                if ($action === 'create') {
                    try {
                        $createdId = FoodRepository::create(
                            (int) $clean['category_id'],
                            (string) $clean['name'],
                            (string) ($clean['description'] ?? ''),
                            (int) $clean['price'],
                            (int) $clean['stock'],
                            (int) $clean['low_stock_level'],
                            $isAvailable,
                            $upload->relativePath,
                            $actorId
                        );
                    } catch (\Throwable $error) {
                        // The insert failed, so the uploaded file has no row to
                        // belong to. Removing it keeps the uploads directory a
                        // true reflection of what the database references.
                        ImageUpload::delete($upload->relativePath);

                        throw $error;
                    }

                    forget_old_input();

                    flash_set('success', sprintf(
                        '"%s" was added to the menu.',
                        (string) $clean['name']
                    ));

                    redirect($pageUrl);
                }

                // -- Update ----------------------------------------------------
                //
                // Only the keys that changed are passed, so an edit that leaves
                // the photo alone cannot clear it, and one that leaves the
                // price alone cannot re-round it.
                $changes = [
                    'name'            => (string) $clean['name'],
                    'description'     => (string) ($clean['description'] ?? ''),
                    'price_cents'     => (int) $clean['price'],
                    'category_id'     => (int) $clean['category_id'],
                    'low_stock_level' => (int) $clean['low_stock_level'],
                    'is_available'    => $isAvailable,
                ];

                $removeImage = input_string('remove_image') === '1';

                if ($upload->uploaded) {
                    $changes['image_path'] = $upload->relativePath;
                } elseif ($removeImage && $product !== null) {
                    $changes['image_path'] = null;
                }

                // An unavailable product with no stock cannot be turned on by
                // an edit that did not notice.
                if ($isAvailable && (int) ($product['stock'] ?? 0) === 0) {
                    $changes['is_available'] = false;
                    flash_set(
                        'info',
                        sprintf(
                            '"%s" was saved, but it stayed off the menu because there is no stock. Restock it from Inventory to sell it again.',
                            (string) $clean['name']
                        )
                    );
                }

                $previousImage = $product['image_path'] ?? null;

                try {
                    FoodRepository::update($id, $changes, $actorId);
                } catch (\Throwable $error) {
                    ImageUpload::delete($upload->relativePath);

                    throw $error;
                }

                // The old photograph is removed only once the row points
                // somewhere else, so a crash in between leaves an orphan file
                // rather than a broken image on a live product.
                if (($upload->uploaded || $removeImage) && $previousImage !== null) {
                    ImageUpload::delete(is_string($previousImage) ? $previousImage : null);
                }

                forget_old_input();

                flash_set('success', sprintf('"%s" was saved.', (string) $clean['name']));

                redirect($pageUrl . with_query(['edit' => $id]));

            case 'activate':
            case 'deactivate':
                $updated = FoodRepository::setAvailability($id, $action === 'activate', $actorId);

                flash_set('success', sprintf(
                    '"%s" is now %s.',
                    (string) $updated['name'],
                    $action === 'activate' ? 'available to students' : 'hidden from the menu'
                ));

                redirect($pageUrl . with_query(list_query()));

            case 'archive':
            case 'restore':
                FoodRepository::setArchived($id, $action === 'archive', $actorId);

                $name = (string) ($product['name'] ?? '');

                flash_set(
                    'success',
                    $action === 'archive'
                        ? sprintf('"%s" was removed from the menu. Its order history is untouched.', $name)
                        : sprintf('"%s" is back on the menu.', $name)
                );

                redirect($pageUrl . with_query(list_query()));

            case 'remove_image':
                FoodRepository::update($id, ['image_path' => null], $actorId);

                $removed = ImageUpload::delete(
                    is_string($product['image_path'] ?? null) ? $product['image_path'] : null
                );

                flash_set(
                    'success',
                    $removed
                        ? 'The photograph was removed.'
                        : 'The photograph was removed from the product.'
                );

                redirect($pageUrl . with_query(['edit' => $id]));

            default:
                throw new ValidationException(
                    ['action' => 'That request could not be understood. Please reload the page.'],
                    'That request could not be understood. Please reload the page.'
                );
        }
    } catch (ValidationException $exception) {
        field_errors_set($exception->errors());
        flash_set('error', $exception->getMessage());

        redirect($backToForm);
    } catch (BusinessRuleException | NotFoundException $exception) {
        flash_set('error', $exception->getMessage());
        redirect($pageUrl . with_query(list_query()));
    }
}

// ---------------------------------------------------------------------------
// Read
// ---------------------------------------------------------------------------

$editId  = query_int('edit');
$editing = $editId > 0 ? FoodRepository::findById($editId) : null;

if ($editId > 0 && $editing === null) {
    flash_set('error', 'That product no longer exists.');
    redirect($pageUrl);
}

$categories = CategoryRepository::all();
$counts     = FoodRepository::adminCounts();

$categoryFilter = query_int('category');
$statusFilter   = query_string('status', 'all');

if (! in_array($statusFilter, ['all', 'available', 'unavailable'], true)) {
    $statusFilter = 'all';
}

$includeArchived = query_string('archived') === '1';

try {
    $listing = FoodRepository::paginate([
        'search'           => FoodRepository::adminSearch(),
        'category_id'      => $categoryFilter > 0 ? $categoryFilter : null,
        'status'           => $statusFilter,
        'include_archived' => $includeArchived,
        'page'             => query_int('page', 1),
    ]);
} catch (ValidationException $exception) {
    // A malformed ?q= is a search box being typed into. Say so and show the
    // unfiltered list rather than serving an error page mid-typing.
    flash_set('error', $exception->getMessage());
    redirect($pageUrl);
}

/** @var list<array<string, mixed>> $products */
$products = $listing['rows'];

/** @var Pagination $pager */
$pager = $listing['pagination'];

$errors = field_errors_take();

// The category <select> needs a blank option so "not chosen" is expressible
// and the server can reject it, rather than the browser defaulting to the
// first category and silently filing a product under it.
$categoryOptions = ['' => '— Choose a category —'];

foreach ($categories as $category) {
    $categoryOptions[(string) $category['id']] = sprintf(
        '%s%s',
        (string) $category['name'],
        (int) $category['is_active'] === 1 ? '' : ' (hidden)'
    );
}

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------

Layout::appStart([
    'title'    => 'Food Management',
    'heading'  => 'Food Management',
    'subtitle' => 'What the canteen sells, what it costs, and whether students can order it right now.',
    'actions'  => '<a class="btn btn--ghost" href="' . e(url('/admin/categories.php')) . '">Categories</a>',
]);

// -- Header figures ----------------------------------------------------------
//
// Real counts, computed in SQL. Nothing here is a placeholder: on a canteen
// with no food yet these read zero, which is the truth.

echo '    <div class="stat-grid">' . "\n";

$tiles = [
    ['On the menu',  $counts['available'],    'Students can order these'],
    ['Marked out',   $counts['unavailable'],  'Switched off, still listed for staff'],
    ['Low or no stock', $counts['low_stock'], 'At or below the alert level'],
    ['Archived',     $counts['archived'],     'Removed, history kept'],
];

foreach ($tiles as [$label, $value, $hint]) {
    echo '      <div class="stat">' . "\n";
    echo '        <span class="stat__body">' . "\n";
    echo '          <span class="stat__label">' . e($label) . "</span>\n";
    echo '          <span class="stat__value tnum">' . e((string) $value) . "</span>\n";
    echo '          <span class="stat__hint">' . e($hint) . "</span>\n";
    echo "        </span>\n";
    echo "      </div>\n";
}

echo "    </div>\n";

if ($counts['low_stock'] > 0) {
    echo '    <div class="alert alert--warning mt-6">' . "\n";
    echo '      <span class="alert__icon">' . Layout::icon('warning') . "</span>\n";
    echo '      <span>' . e((string) $counts['low_stock']) . ' product'
        . ($counts['low_stock'] === 1 ? ' is' : 's are') . ' at or below the low-stock level. '
        . '<a href="' . e(url('/admin/inventory.php')) . '">Open Inventory</a> to restock.</span>' . "\n";
    echo "    </div>\n";
}

// -- Add / edit form ---------------------------------------------------------

$isEdit = $editing !== null;

$formValue = static function (string $key, string $default = '') use ($editing, $isEdit): string {
    if ($isEdit) {
        return (string) ($editing[$key] ?? $default);
    }

    return (string) old($key, $default);
};

$checked = static function (bool $isTrue): string {
    return $isTrue ? ' checked' : '';
};

echo '    <div class="card mt-6" id="product-form">' . "\n";
echo '      <div class="card__header">' . "\n";
echo '        <h2 class="card__title">'
    . ($isEdit ? 'Edit product' : 'Add a product') . "</h2>\n";

if ($isEdit) {
    echo '        <a class="btn btn--quiet btn--sm" href="' . e(url($pageUrl)) . '">Add another</a>' . "\n";
}

echo "      </div>\n";
echo '      <div class="card__body">' . "\n";

echo '        <form method="post" action="'
    . e($pageUrl . ($isEdit ? with_query(['edit' => $editId]) : ''))
    . '" enctype="multipart/form-data" novalidate>' . "\n";
echo '          ' . Csrf::field() . "\n";
echo '          <input type="hidden" name="action" value="' . ($isEdit ? 'update' : 'create') . '">' . "\n";

if ($isEdit) {
    echo '          <input type="hidden" name="id" value="' . $editId . '">' . "\n";
}

echo '          <div class="form-grid">' . "\n";

Layout::field('name', 'Product name', $errors, [
    'value'     => $formValue('name'),
    'maxlength' => NAME_MAX,
    'autofocus' => true,
    'hint'      => 'As it appears on the menu, e.g. Chicken Rice.',
]);

Layout::select('category_id', 'Category', $errors, $categoryOptions, [
    'value' => $isEdit
        ? (string) $editing['category_id']
        : (string) old('category_id', ''),
    'hint'  => 'Hidden categories cannot be chosen for new products.',
]);

Layout::field('price', 'Price (₱)', $errors, [
    'type'      => 'text',
    'value'     => $isEdit
        ? cents_to_amount(to_cents((string) $editing['price']))
        : (string) old('price', ''),
    'inputmode' => 'decimal',
    'placeholder' => '75.00',
    'maxlength' => 10,
    'spellcheck' => false,
    'hint'      => 'Two decimal places, e.g. 75.00',
]);

Layout::field('low_stock_level', 'Low-stock alert at', $errors, [
    'type'      => 'number',
    'value'     => $formValue('low_stock_level', '5'),
    'min'       => 0,
    'max'       => 100000,
    'required'  => false,
    'hint'      => 'When stock reaches this number, Inventory warns you.',
]);

Layout::textarea('description', 'Description', $errors, [
    'value'     => $formValue('description'),
    'maxlength' => DESCRIPTION_MAX,
    'rows'      => 2,
    'hint'      => 'Optional. Shown under the product name on the menu.',
]);

if (! $isEdit) {
    Layout::field('stock', 'Opening stock', $errors, [
        'type'      => 'number',
        'value'     => (string) old('stock', '0'),
        'min'       => 0,
        'max'       => 1000000,
        'required'  => false,
        'hint'      => 'Counted once here. After this, stock moves only from Inventory, where every change is recorded.',
    ]);
} else {
    // Read-only stock, with the reason stated rather than the field simply
    // missing. A missing field reads as an oversight; a disabled one with an
    // explanation reads as a decision.
    echo '            <div class="field">' . "\n";
    echo '              <label class="field__label" for="field-stock-readonly">Current stock</label>' . "\n";
    echo '              <input type="text" id="field-stock-readonly" value="'
        . (int) $editing['stock'] . '" readonly aria-describedby="field-stock-readonly-describe">' . "\n";
    echo '              <div id="field-stock-readonly-describe">' . "\n";
    echo '                <p class="field__hint">'
        . '<a href="' . e(url('/admin/inventory.php')) . '">Adjust stock in Inventory</a> — '
        . 'every change there is recorded with who did it and why.</p>' . "\n";
    echo "              </div>\n";
    echo "            </div>\n";
}

Layout::file('photo', 'Photograph', $errors, [
    'hint' => sprintf(
        'Optional. JPG, PNG or WEBP, up to %s and %d pixels on the longest edge. Leave empty to keep the current photo.',
        human_bytes(Config::int('security.upload_max_bytes', 2_097_152)),
        ImageUpload::MAX_DIMENSION
    ),
]);

if ($isEdit && ImageUpload::isManaged(is_string($editing['image_path'] ?? null) ? $editing['image_path'] : null)) {
    echo '            <div class="field">' . "\n";
    echo '              <span class="field__label">Current photograph</span>' . "\n";
    echo '              <img src="' . e(ImageUpload::urlFor(is_string($editing['image_path']) ? $editing['image_path'] : null))
        . '" alt="" width="160" height="100" style="border-radius:var(--radius-sm);object-fit:cover">' . "\n";
    echo '              <div><label class="check" for="field-remove_image">' . "\n";
    echo '                <input type="checkbox" id="field-remove_image" name="remove_image" value="1">' . "\n";
    echo '                <span>Remove this photo</span>' . "\n";
    echo "              </label></div>\n";
    echo "            </div>\n";
}

echo '            <div class="field">' . "\n";
echo '              <label class="check" for="field-is_available">' . "\n";
echo '                <input type="checkbox" id="field-is_available" name="is_available" value="1"'
    . $checked($isEdit ? (int) $editing['is_available'] === 1 : (string) old('is_available', '1') === '1')
    . '>' . "\n";
echo '                <span>Students can order this today</span>' . "\n";
echo "              </label>\n";
echo '              <p class="field__hint">Untick when you have run out. It stays listed for staff but cannot be added to a cart.</p>' . "\n";
echo "            </div>\n";

echo "          </div>\n";

echo '          <div class="form-actions">' . "\n";
echo '            <button class="btn" type="submit" data-submit>'
    . ($isEdit ? 'Save changes' : 'Add product') . "</button>\n";

if ($isEdit) {
    echo '            <a class="btn btn--ghost" href="' . e(url($pageUrl)) . '">Cancel</a>' . "\n";
    echo '            <a class="btn btn--ghost" href="'
        . e(url('/admin/inventory.php') . '?focus=' . $editId) . '">Adjust stock</a>' . "\n";
}

echo "          </div>\n";
echo "        </form>\n";
echo "      </div>\n";
echo "    </div>\n";

// -- Filters -----------------------------------------------------------------

$categorySelect = '';

if ($categories === []) {
    // No categories exist yet, so a product cannot be created. Say so plainly
    // instead of rendering an empty dropdown that submits nothing.
    echo '    <div class="alert alert--warning mt-6">' . "\n";
    echo '      <span class="alert__icon">' . Layout::icon('warning') . "</span>\n";
    echo '      <span>There are no categories yet, so products cannot be added. '
        . '<a href="' . e(url('/admin/categories.php')) . '">Create a category first</a>.</span>' . "\n";
    echo "    </div>\n";
} else {
    $categorySelect .= '  <div class="field">' . "\n"
        . '    <label class="field__label" for="filter-category">Category</label>' . "\n"
        . '    <select id="filter-category" name="category">' . "\n"
        . '      <option value="">All categories</option>' . "\n";

    foreach ($categories as $category) {
        $categorySelect .= '      <option value="' . (int) $category['id'] . '"'
            . ($categoryFilter === (int) $category['id'] ? ' selected' : '') . '>'
            . e((string) $category['name']) . "</option>\n";
    }

    $categorySelect .= "    </select>\n  </div>\n";

    $statusSelect = '  <div class="field field--narrow">' . "\n"
        . '    <label class="field__label" for="filter-status">Availability</label>' . "\n"
        . '    <select id="filter-status" name="status">' . "\n";

    foreach (['all' => 'All', 'available' => 'Available', 'unavailable' => 'Marked out'] as $value => $label) {
        $statusSelect .= '      <option value="' . e($value) . '"'
            . ($statusFilter === $value ? ' selected' : '') . '>' . e($label) . "</option>\n";
    }

    $statusSelect .= "    </select>\n  </div>\n";

    $archiveSelect = '  <div class="field field--narrow">' . "\n"
        . '    <label class="check" for="filter-archived" style="padding-top:1.75rem">' . "\n"
        . '      <input type="checkbox" id="filter-archived" name="archived" value="1"'
        . $checked($includeArchived) . '>' . "\n"
        . '      <span>Show archived</span>' . "\n"
        . "    </label>\n  </div>\n";

    $keep = list_query();

    echo '    <div class="card mt-6">' . "\n";

    Layout::filterBar(
        $pageUrl,
        [$categorySelect, $statusSelect, $archiveSelect],
        $keep
    );

    if ($keep !== []) {
        echo '  <p class="filter-summary">Showing a filtered list. '
            . '<a href="' . e(url($pageUrl)) . '">Clear filters</a>.</p>' . "\n";
    }
}

// -- Table -------------------------------------------------------------------

echo '    <div class="card mt-6">' . "\n";
echo '      <div class="table-wrap">' . "\n";
echo '        <table class="table table--responsive">' . "\n";
echo '          <caption class="visually-hidden">Food products</caption>' . "\n";
echo '          <thead>' . "\n";
echo '            <tr>' . "\n";
echo '              <th scope="col">Product</th>' . "\n";
echo '              <th scope="col">Category</th>' . "\n";
echo '              <th scope="col" class="is-numeric">Price</th>' . "\n";
echo '              <th scope="col" class="is-numeric">Stock</th>' . "\n";
echo '              <th scope="col">Status</th>' . "\n";
echo '              <th scope="col">Actions</th>' . "\n";
echo "            </tr>\n";
echo "          </thead>\n";
echo "          <tbody>\n";

if ($products === []) {
    echo '            <tr><td class="table__empty" colspan="6">'
        . (list_query() === []
            ? 'No products yet. Add the first one using the form above.'
            : 'No products match this filter.')
        . "</td></tr>\n";
}

foreach ($products as $product) {
    $productId     = (int) $product['id'];
    $stock         = (int) $product['stock'];
    $isAvailable   = (int) $product['is_available'] === 1;
    $isArchived    = (int) $product['is_archived'] === 1;
    $isLow         = $stock <= (int) $product['low_stock_level'];
    $imagePath     = is_string($product['image_path'] ?? null) ? $product['image_path'] : null;
    $categoryOff   = (int) ($product['category_active'] ?? 1) === 0;

    echo '            <tr' . ($productId === $editId ? ' style="background:var(--surface-2)"' : '') . '>' . "\n";

    echo '              <td data-label="Product">' . "\n";
    echo '                <div class="flow-right" style="align-items:center">' . "\n";
    echo '                  <img src="' . e(ImageUpload::urlFor($imagePath)) . '" alt="" width="44" height="34"'
        . ' style="border-radius:var(--radius-sm);object-fit:cover;background:var(--surface-2)">' . "\n";
    echo '                  <span>' . "\n";
    echo '                    <span class="text-strong">' . e((string) $product['name']) . "</span>\n";
    $description = trim((string) ($product['description'] ?? ''));
    if ($description !== '') {
        echo '                    <span class="stat__hint">' . e($description) . "</span>\n";
    }
    echo "                  </span>\n";
    echo "                </div>\n";
    echo "              </td>\n";

    echo '              <td data-label="Category">' . e((string) $product['category']);

    if ($categoryOff) {
        echo ' <span class="badge badge--completed">hidden</span>';
    }

    echo "</td>\n";

    echo '              <td data-label="Price" class="is-numeric">'
        . e(peso((string) $product['price'])) . "</td>\n";

    echo '              <td data-label="Stock" class="is-numeric">' . $stock;

    if ($isLow && ! $isArchived) {
        echo ' <span class="badge ' . ($stock === 0 ? 'badge--cancelled' : 'badge--pending') . '">'
            . ($stock === 0 ? 'none' : 'low') . "</span>\n";
    } else {
        echo "\n";
    }

    echo "              </td>\n";

    echo '              <td data-label="Status">' . "\n";

    if ($isArchived) {
        echo '                <span class="badge badge--completed">Archived</span>' . "\n";
    } elseif ($isAvailable) {
        echo '                <span class="badge badge--ready">On menu</span>' . "\n";
    } else {
        echo '                <span class="badge badge--pending">Marked out</span>' . "\n";
    }

    echo "              </td>\n";

    echo '              <td data-label="Actions">' . "\n";
    echo '                <div class="flow-right">' . "\n";

    echo '                  <a class="btn btn--sm btn--quiet" href="'
        . e($pageUrl . with_query(['edit' => $productId])) . '">Edit</a>' . "\n";

    // Availability. Not offered on an archived product: restoring it is the
    // thing that makes it sellable again, and offering two toggles for one
    // state invites confusion.
    if (! $isArchived) {
        echo '                  <form method="post" action="' . e($pageUrl) . '">' . "\n";
        echo '                    ' . Csrf::field() . "\n";
        echo '                    <input type="hidden" name="action" value="'
            . ($isAvailable ? 'deactivate' : 'activate') . '">' . "\n";
        echo '                    <input type="hidden" name="id" value="' . $productId . '">' . "\n";
        echo '                    <button class="btn btn--sm" type="submit">'
            . ($isAvailable ? 'Mark out' : 'Put on menu') . "</button>\n";
        echo "                  </form>\n";
    }

    if (! $isArchived && $stock === 0 && $isAvailable) {
        // setAvailability() refuses this server-side. Offering the button would
        // be offering an action that cannot succeed.
        echo '                  <span class="stat__hint">restock to sell</span>' . "\n";
    }

    echo '                  <form method="post" action="' . e($pageUrl) . '"'
        . ($isArchived
            ? ''
            : ' data-confirm="Remove “' . e((string) $product['name']) . '” from the menu?'
              . ' Students will no longer be able to order it and its order history is kept.')
        . '>' . "\n";
    echo '                    ' . Csrf::field() . "\n";
    echo '                    <input type="hidden" name="action" value="'
        . ($isArchived ? 'restore' : 'archive') . '">' . "\n";
    echo '                    <input type="hidden" name="id" value="' . $productId . '">' . "\n";
    echo '                    <button class="btn btn--sm ' . ($isArchived ? 'btn--quiet' : 'btn--ghost') . '" type="submit">'
        . ($isArchived ? 'Restore' : 'Archive') . "</button>\n";
    echo "                  </form>\n";

    echo "                </div>\n";
    echo "              </td>\n";
    echo "            </tr>\n";
}

echo "          </tbody>\n";
echo "        </table>\n";
echo "      </div>\n";

Layout::pagination($pager, $pageUrl, list_query());

echo "    </div>\n";

Layout::appEnd();