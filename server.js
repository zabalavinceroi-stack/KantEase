const mariadb = require("mariadb");
const http = require("http");
const crypto = require("crypto");

const DB_HOST = "localhost";
const DB_USER = "vinxo";
const DB_PASSWORD = "Shoto12+_)";
const DB_NAME = "canteen_db";
const ADMIN_CODE = "canteen2026";
const FOOD_CATEGORIES = ["Meals", "Snacks", "Drinks", "Desserts", "Others"];
const PORT = Number(process.env.PORT || 3000);
const SESSION_MS = 8 * 60 * 60 * 1000;

let pool;
const sessions = new Map();

const page = `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>KantEase Accounts</title>
  <script>
    (function() {
      var path = window.location.pathname;
      document.documentElement.setAttribute("data-page",
        path === "/signup" ? "signup" : path === "/" ? "login" : "panel");
      var theme = "";
      try { theme = localStorage.getItem("kantease-theme") || ""; } catch (error) {}
      if (theme !== "light" && theme !== "dark") {
        theme = window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
      }
      document.documentElement.setAttribute("data-theme", theme);
    })();
  </script>
  <style>
    :root {
      color-scheme: light;
      --bg: #F4F8FB;
      --card: #1F4E79;
      --text: #16324F;
      --card-text: #FFFFFF;
      --muted: #D7E6F2;
      --page-muted: #526B82;
      --border: #456B8C;
      --main: #FF8A3D;
      --main-hover: #E87525;
      --header-text: #332012;
      --table-head-text: #FFFFFF;
      --table-head: #1F4E79;
      --row-hover: #2A5F8F;
      --input-bg: #163E62;
      --focus: #FF8A3D;
      --shadow: #16324F26;
      --modal-overlay: rgba(0, 0, 0, .62);
      --modal-shadow: #0005;
      --success-bg: #e3f4e9;
      --success-text: #145a39;
      --error-bg: #fff0ef;
      --error-text: #8e211c;
      --danger: #a82d27;
      --danger-hover: #85201c;
      --soft-bg: #163E62;
      --profile-text: #D7E6F2;
      --sidebar-bg: #1F4E79;
      --subtle: #2A5F8F;
      --accent-soft: #FFE4D1;
      --accent-text: #8A3500;
      --warning-bg: #fff4d8;
      --warning-text: #9a6b00;
      --info-bg: #e4efff;
      --info-text: #245b9c;
      --transparent: transparent;
      --speed: .25s;
      --radius: 9px;
      --space: 16px;
    }
    :root[data-theme="dark"] {
      color-scheme: dark;
      --bg: #0D1B2A;
      --card: #1F3D5C;
      --text: #F4F8FB;
      --card-text: #F4F8FB;
      --muted: #B8CAD8;
      --page-muted: #A8BAC9;
      --border: #3A5874;
      --main: #FF9B5C;
      --main-hover: #FFB27F;
      --header-text: #2B180E;
      --table-head-text: #F4F8FB;
      --table-head: #1F4E79;
      --row-hover: #294B6B;
      --input-bg: #122A40;
      --focus: #FFB27F;
      --shadow: #0008;
      --modal-overlay: rgba(5, 12, 9, .78);
      --modal-shadow: #0008;
      --success-bg: #244936;
      --success-text: #b7e5c7;
      --error-bg: #512d2c;
      --error-text: #f0b8b4;
      --danger: #a63e39;
      --danger-hover: #bf4e47;
      --soft-bg: #193651;
      --profile-text: #D0DFEA;
      --sidebar-bg: #1F3D5C;
      --subtle: #294B6B;
      --accent-soft: #633D2A;
      --accent-text: #FFD2B5;
      --warning-bg: #493c20;
      --warning-text: #f1c75d;
      --info-bg: #243e5f;
      --info-text: #b6d4ff;
      --transparent: transparent;
      --speed: .25s;
      --radius: 9px;
      --space: 16px;
    }
    * { box-sizing: border-box; transition: background-color .2s, color .2s, border-color .2s, box-shadow .2s; }
    html, body { background: var(--bg); color: var(--text); }
    body { margin: 0; min-height: 100vh; font: 16px Arial, sans-serif; }
    header { background: var(--sidebar-bg); color: var(--card-text); padding: 20px; text-align: center; }
    header h1 { margin: 0; font-size: 25px; }
    main { width: min(900px, calc(100% - 32px)); margin: 36px auto; }
    .card { background: var(--card); color: var(--card-text); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; box-shadow: 0 8px 24px var(--shadow); }
    .auth { position: relative; max-width: 460px; margin: 0 auto; padding: 26px; }
    html[data-page="signup"] .auth { max-width: 570px; }
    html[data-page="login"] body > header, html[data-page="signup"] body > header, body.dashboard-page > header { display: none; }
    html[data-page="login"] main, html[data-page="signup"] main { width: min(100% - 32px, 570px); margin: 0 auto; min-height: 100vh; display: flex; flex-direction: column; justify-content: center; }
    h2 { margin: 0 0 18px; font-size: 22px; }
    label { display: block; margin: 14px 0 6px; font-weight: 600; }
    input, select { width: 100%; padding: 11px; border: 1px solid var(--border); border-radius: 6px; background: var(--input-bg); color: var(--card-text); font: inherit; }
    input:focus, select:focus { outline: 2px solid var(--focus); border-color: var(--main); }
    button { border: 0; border-radius: 6px; padding: 11px 16px; background: var(--main); color: var(--header-text); font: inherit; font-weight: 700; cursor: pointer; transition: background-color .2s, color .2s, transform .15s ease-out; }
    button:hover:not(:disabled) { background: var(--main-hover); }
    button:active:not(:disabled) { transform: scale(.97); }
    button:disabled { cursor: not-allowed; opacity: .55; }
    .theme-toggle { position: absolute; top: 16px; right: 16px; padding: 8px 11px; border: 1px solid var(--border); background: var(--card); color: var(--card-text); font-size: 14px; }
    .theme-toggle:hover:not(:disabled) { background: var(--row-hover); }
    .auth-heading { position: relative; display: flex; justify-content: flex-start; align-items: center; min-height: 38px; margin: 16px 0 20px; }
    .auth-heading h2 { margin: 0; }
    .auth-heading .theme-toggle { position: fixed; top: 18px; right: 22px; }
    .brand-line { display: flex; align-items: center; gap: 9px; color: var(--main); font-size: 21px; }
    .brand-mark { display: grid; place-items: center; width: 34px; height: 34px; border-radius: 8px; background: var(--main); color: var(--header-text); font-size: 17px; }
    .auth-title { margin: 0 0 7px; font-size: 23px; }
    .auth-subtitle { margin: 0 0 20px; color: var(--muted); }
    .auth-footer { margin: 20px 0 0; color: var(--muted); text-align: center; }
    .auth-page-footer { margin: 18px 0 0; color: var(--muted); font-size: 12px; text-align: center; }
    .signup-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2px 14px; }
    .signup-grid label { margin-top: 10px; }
    .auth-divider { margin-top: 20px; border: 0; border-top: 1px solid var(--border); }
    .full { width: 100%; margin-top: 18px; }
    a { color: var(--main); }
    .message { padding: 11px 13px; border-radius: 6px; margin: 0 0 14px; animation: slideIn var(--speed) ease-out both; }
    .success { color: var(--success-text); background: var(--success-bg); }
    .error { color: var(--error-text); background: var(--error-bg); }
    .hidden { display: none !important; }
    .check { display: flex; align-items: flex-start; gap: 9px; font-weight: 400; }
    .check input { width: auto; margin-top: 3px; }
    .terms-popup-overlay { position: fixed; inset: 0; z-index: 10; display: grid; place-items: center; padding: 18px; background: var(--modal-overlay); }
    .terms-popup-panel { width: min(580px, 100%); max-height: 85vh; display: flex; flex-direction: column; background: var(--card); color: var(--card-text); border: 1px solid var(--border); border-radius: 10px; padding: 26px; box-shadow: 0 12px 36px var(--modal-shadow); }
    .terms-popup-panel h2 { margin-bottom: 14px; }
    .terms-popup-content { overflow-y: auto; padding-right: 8px; }
    .terms-popup-content p { margin: 0 0 13px; line-height: 1.5; }
    .terms-popup-close { align-self: flex-end; margin-top: 12px; }
    .topline { display: flex; justify-content: space-between; align-items: center; gap: 14px; margin-bottom: 18px; }
    .topline h2 { margin: 0; }
    .profile { display: grid; grid-template-columns: max-content 1fr; gap: 10px 18px; margin: 18px 0; padding: 18px; border: 1px solid var(--border); border-radius: 8px; background: var(--card); color: var(--card-text); }
    .profile strong { color: var(--profile-text); }
    .profile-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(280px, 1fr); gap: 20px; align-items: start; margin: 18px 0; padding: 18px; border: 1px solid var(--border); border-radius: 8px; background: var(--card); color: var(--card-text); }
    .profile-layout .profile { margin: 0; padding: 0; border: 0; background: transparent; }
    .profile-edit-form { display: grid; gap: 10px; padding: 0; border: 0; background: transparent; }
    .profile-edit-form label { margin: 0; color: var(--muted); font-size: 13px; }
    .profile-edit-form button { justify-self: start; }
    .toolbar { display: flex; gap: 12px; align-items: center; margin: 18px 0; }
    .toolbar select { width: auto; min-width: 150px; }
    .table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; white-space: nowrap; }
    th, td { padding: 11px 10px; border-bottom: 1px solid var(--border); text-align: left; }
    #admin-orders-list { overflow-x: hidden; }
    .admin-orders-table { table-layout: fixed; white-space: normal; }
    .admin-orders-table th, .admin-orders-table td { padding: 9px 6px; font-size: 13px; white-space: normal; overflow-wrap: anywhere; }
    .admin-orders-table th:nth-child(1) { width: 7%; }
    .admin-orders-table th:nth-child(2) { width: 9%; }
    .admin-orders-table th:nth-child(3) { width: 13%; }
    .admin-orders-table th:nth-child(4) { width: 23%; }
    .admin-orders-table th:nth-child(5) { width: 9%; }
    .admin-orders-table th:nth-child(6) { width: 9%; }
    .admin-orders-table th:nth-child(7) { width: 10%; }
    .admin-orders-table th:nth-child(8) { width: 12%; }
    .admin-orders-table th:nth-child(9) { width: 8%; }
    .admin-orders-table .order-badge { white-space: normal; }
    .admin-orders-table td button { max-width: 100%; padding: 6px; }
    th { background: var(--table-head); color: var(--table-head-text); }
    tbody tr { animation: fadeIn .25s ease-out both; }
    tbody tr:hover { background: var(--row-hover); }
    td button { padding: 7px 10px; background: var(--danger); }
    td button:hover:not(:disabled) { background: var(--danger-hover); }
    .search-tools { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin: 14px 0 5px; }
    .search-tools input { flex: 1; width: auto; min-width: 180px; }
    .search-tools input[type="date"] { flex: 0 1 145px; min-width: 135px; }
    .search-tools select { flex: 0 1 165px; width: auto; min-width: 145px; }
    .search-tools .clear-search { background: var(--main); }
    .search-date-group { display: flex; flex-direction: column; gap: 4px; padding: 6px 9px; border: 1px solid var(--border); border-radius: 6px; background: var(--soft-bg); }
    .search-date-heading { color: var(--profile-text); font-size: 12px; font-weight: 700; }
    .search-date-fields { display: flex; align-items: center; gap: 8px; }
    .search-date-fields label { display: flex; align-items: center; gap: 5px; margin: 0; color: var(--muted); font-size: 12px; font-weight: 400; }
    .search-date-fields input[type="date"] { width: 145px; min-width: 130px; padding: 7px; }
    .search-count { min-height: 20px; margin: 0 0 9px; color: var(--muted); font-size: 13px; }
    body.dashboard-page main { width: 100%; max-width: none; margin: 0; }
    .dashboard-layout { min-height: 100vh; }
    .sidebar { position: fixed; inset: 0 auto 0 0; z-index: 5; display: flex; flex-direction: column; width: 205px; padding: 22px 14px; border-right: 1px solid var(--border); background: var(--sidebar-bg); color: var(--card-text); }
    .sidebar-brand { margin: 0 0 24px; color: var(--main); font-size: 23px; }
    .sidebar-menu { display: grid; gap: 7px; }
    .sidebar-menu button { width: 100%; text-align: left; background: var(--transparent); color: var(--muted); font-weight: 500; transition: transform var(--speed) ease, background-color .2s, color .2s; }
    .sidebar-menu button:hover:not(:disabled) { background: var(--row-hover); transform: translateX(3px); }
    .sidebar-menu button.selected { background: var(--main); color: var(--header-text); }
    .sidebar-user { margin-top: auto; padding-top: 18px; border-top: 1px solid var(--border); }
    .sidebar-name { margin: 0 0 5px; font-weight: 700; }
    .sidebar-id { margin: 0 0 9px; color: var(--muted); font-size: 13px; }
    .role-badge { display: inline-block; margin-bottom: 14px; padding: 4px 9px; border-radius: 99px; background: var(--soft-bg); color: var(--main); font-size: 12px; font-weight: 700; }
    .sidebar-logout { display: block; width: 100%; }
    .dashboard-content { min-width: 0; min-height: 100vh; margin-left: 205px; padding: 0 28px 30px; background: var(--bg); }
    .dashboard-topbar { display: flex; align-items: center; gap: 12px; min-height: 60px; margin: 0 -28px 28px; padding: 0 28px; border-bottom: 1px solid var(--border); background: var(--card); color: var(--card-text); }
    .dashboard-topbar h1 { margin: 0; font-size: 24px; }
    .dashboard-topbar .theme-toggle { position: static; margin-left: auto; }
    .mobile-menu-button { display: none; }
    .dashboard-card { width: 100%; min-width: 0; padding: 20px; background: var(--card); color: var(--card-text); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: 0 5px 18px var(--shadow); }
    .dashboard-card, .dashboard-stat, .admin-summary-card, .admin-dashboard-panel, .menu-item-card { transition: transform var(--speed) ease, box-shadow var(--speed) ease, background-color .2s, border-color .2s; }
    .dashboard-card:hover, .dashboard-stat:hover, .admin-summary-card:hover, .admin-dashboard-panel:hover, .menu-item-card:hover { transform: translateY(-3px); box-shadow: 0 10px 24px var(--shadow); }
    .dashboard-card h2 { margin-bottom: 16px; }
    .section { display: none; }
    .section.active { display: block; animation: riseIn var(--speed) ease-out both; }
    .auth:not(.hidden) { animation: riseIn .3s ease-out both; }
    .dashboard-note { color: var(--page-muted); }
    .welcome-line { display: flex; justify-content: space-between; align-items: flex-end; gap: 15px; }
    .welcome-line h2 { margin-bottom: 5px; }
    .dashboard-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; margin: 20px 0; }
    .dashboard-stat { padding: 17px 19px; border: 1px solid var(--border); border-radius: 9px; background: var(--card); color: var(--card-text); box-shadow: 0 4px 14px var(--shadow); }
    .dashboard-stat span { color: var(--muted); font-size: 13px; }
    .dashboard-stat strong { display: block; margin: 7px 0 3px; font-size: 23px; }
    .dashboard-stat small { color: var(--muted); }
    .pickup-banner { display: flex; align-items: center; gap: 14px; margin: 18px 0; padding: 16px 18px; border-radius: 8px; background: var(--accent-soft); }
    .pickup-banner p { margin: 4px 0 0; color: var(--muted); }
    .pickup-icon { display: grid; place-items: center; width: 38px; height: 38px; border-radius: 8px; background: var(--card); color: var(--main); }
    .latest-card { overflow: hidden; padding: 0; }
    .latest-heading { display: flex; justify-content: space-between; align-items: center; padding: 17px 20px; }
    .latest-heading h2 { margin: 0; font-size: 18px; }
    .latest-heading button { padding: 6px 0; background: var(--transparent); color: var(--main); }
    .latest-heading button:hover:not(:disabled) { background: var(--transparent); color: var(--main-hover); }
    .menu-layout { display: grid; grid-template-columns: minmax(0, 1fr) 275px; gap: 18px; align-items: start; }
    .menu-cards { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-top: 14px; }
    .menu-item-card { overflow: hidden; border: 1px solid var(--border); border-radius: 9px; background: var(--card); color: var(--card-text); }
    .menu-item-card.sold-out { opacity: .55; }
    .menu-item-art { display: grid; place-items: center; height: 76px; background: var(--subtle); font-size: 34px; }
    .menu-item-info { padding: 13px; }
    .menu-item-info h3 { margin: 0 0 12px; font-size: 15px; }
    .menu-item-bottom { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .menu-price { color: var(--main); font-weight: 700; }
    .stock-badge { padding: 4px 8px; border-radius: 99px; background: var(--accent-soft); color: var(--accent-text); font-size: 11px; white-space: nowrap; }
    .stock-badge.low { background: var(--warning-bg); color: var(--warning-text); }
    .stock-badge.sold-out { background: var(--error-bg); color: var(--error-text); }
    .category-label { display: inline-block; margin-bottom: 9px; padding: 4px 8px; border-radius: 99px; background: var(--subtle); color: var(--muted); font-size: 11px; }
    .category-chips { display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0; }
    .category-chip { padding: 7px 12px; border: 1px solid var(--border); background: var(--card); color: var(--card-text); }
    .category-chip.selected { border-color: var(--main); background: var(--main); color: var(--header-text); }
    .menu-item-controls { display: grid; grid-template-columns: 85px 1fr; gap: 8px; margin-top: 11px; }
    .menu-item-controls input { min-width: 0; padding: 8px; }
    .menu-item-controls button { margin: 0; }
    .export-row { display: flex; justify-content: flex-end; margin: 12px 0; }
    .password-card { max-width: 620px; margin-top: 18px; }
    .password-card h2 { margin-bottom: 12px; }
    .menu-item-info button { width: 100%; margin-top: 11px; padding: 8px 10px; }
    .order-summary { position: sticky; top: 16px; }
    .order-summary h2 { margin: 0 0 15px; }
    .cart-list { display: grid; gap: 9px; margin: 14px 0; }
    .cart-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px 12px; padding: 10px 0; border-bottom: 1px solid var(--border); }
    .cart-row:last-child { border-bottom: 0; }
    .cart-item-name { font-weight: 700; }
    .cart-item-detail { color: var(--muted); font-size: 13px; }
    .cart-row button { grid-column: 2; grid-row: 1 / span 2; align-self: center; padding: 6px 9px; background: var(--danger); }
    .cart-total { display: flex; justify-content: space-between; padding-top: 12px; border-top: 1px solid var(--border); font-size: 18px; }
    .cart-empty { color: var(--muted); }
    .pickup-note { margin: 0 0 16px; padding: 13px; border-radius: 7px; background: var(--subtle); color: var(--muted); font-size: 13px; }
    .section-intro { margin: -8px 0 18px; color: var(--page-muted); }
    .orders-search-card { margin-bottom: 16px; }
    .section-table-card { overflow: hidden; padding: 0; }
    .section-table-card .table-wrap { padding: 0 18px; }
    .section-table-card .search-tools { padding: 0 18px; }
    .section-table-card .search-count { padding: 0 18px; }
    .section-table-card th { background: var(--subtle); color: var(--muted); }
    .order-badge { display: inline-block; padding: 4px 9px; border-radius: 99px; font-size: 12px; font-weight: 700; }
    .status-pending { background: var(--warning-bg); color: var(--warning-text); }
    .status-preparing { background: var(--info-bg); color: var(--info-text); }
    .status-ready { background: var(--success-bg); color: var(--success-text); }
    .status-completed { background: var(--subtle); color: var(--muted); }
    .status-cancelled { background: var(--error-bg); color: var(--error-text); }
    .payment-unpaid { background: var(--warning-bg); color: var(--warning-text); }
    .payment-paid { background: var(--success-bg); color: var(--success-text); }
    .order-action { padding: 7px 12px; }
    .order-editor-overlay { position: fixed; inset: 0; z-index: 12; display: grid; place-items: center; padding: 16px; background: var(--modal-overlay); }
    .order-editor-panel { display: flex; flex-direction: column; width: min(760px, 100%); max-height: 92vh; overflow: hidden; border: 1px solid var(--border); border-radius: 10px; background: var(--card); color: var(--card-text); box-shadow: 0 12px 36px var(--modal-shadow); }
    .order-editor-heading { padding: 20px 22px 12px; border-bottom: 1px solid var(--border); }
    .order-editor-heading h2 { margin: 0 0 6px; }
    .order-editor-meta { margin: 0; color: var(--muted); font-size: 13px; }
    .order-editor-content { overflow-y: auto; padding: 18px 22px; }
    .order-editor-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .order-editor-fields label { margin: 0; }
    .order-items { margin-top: 18px; overflow-x: auto; }
    .order-items table { min-width: 570px; }
    .order-items th { background: var(--subtle); color: var(--muted); }
    .order-items input { width: 82px; padding: 7px; }
    .order-items .remove-order-item { padding: 6px 9px; background: var(--danger); }
    .order-add-row { display: grid; grid-template-columns: 1fr 100px auto; align-items: end; gap: 10px; margin-top: 14px; }
    .order-add-row label { margin: 0; }
    .order-editor-total { display: flex; justify-content: space-between; margin: 18px 0; padding-top: 14px; border-top: 1px solid var(--border); font-size: 18px; }
    .order-editor-note { margin-top: 14px; }
    .order-editor-note label { margin-top: 0; }
    .order-editor-actions { display: flex; justify-content: flex-end; gap: 10px; padding: 14px 22px; border-top: 1px solid var(--border); }
    .order-editor-actions .secondary, .inventory-form .secondary { border: 1px solid var(--border); background: var(--card); color: var(--card-text); }
    .order-editor-actions .secondary:hover:not(:disabled), .inventory-form .secondary:hover:not(:disabled) { background: var(--row-hover); }
    .account-actions { display: flex; gap: 6px; }
    td button.account-edit { margin: 0; background: var(--main); }
    td button.account-edit:hover:not(:disabled) { background: var(--main-hover); }
    .account-editor-overlay { position: fixed; inset: 0; z-index: 13; display: grid; place-items: center; padding: 16px; background: var(--modal-overlay); }
    .account-editor-panel { width: min(500px, 100%); max-height: 92vh; overflow-y: auto; border: 1px solid var(--border); border-radius: 10px; padding: 22px; background: var(--card); color: var(--card-text); box-shadow: 0 12px 36px var(--modal-shadow); }
    .account-editor-panel h2 { margin: 0 0 16px; }
    .account-editor-panel label { margin-top: 12px; }
    .account-editor-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
    .account-editor-actions .secondary { border: 1px solid var(--border); background: var(--card); color: var(--card-text); }
    .account-editor-actions .secondary:hover:not(:disabled) { background: var(--row-hover); }
    .admin-dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 16px; margin-bottom: 20px; }
    .admin-summary-card { display: flex; align-items: center; gap: 14px; padding: 18px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--card); color: var(--card-text); box-shadow: 0 5px 18px var(--shadow); animation: riseIn var(--speed) ease-out both; }
    .admin-summary-card:nth-child(2) { animation-delay: .05s; }
    .admin-summary-card:nth-child(3) { animation-delay: .1s; }
    .admin-summary-card:nth-child(4) { animation-delay: .15s; }
    .admin-summary-icon { display: grid; place-items: center; flex: 0 0 44px; width: 44px; height: 44px; border-radius: 10px; background: var(--accent-soft); font-size: 22px; }
    .admin-summary-card > div > span { display: block; color: var(--muted); font-size: 13px; }
    .admin-summary-card strong { display: block; margin-top: 6px; font-size: 23px; }
    .admin-status-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 20px; }
    .admin-status-card { display: flex; justify-content: space-between; align-items: center; padding: 14px 16px; border: 1px solid var(--border); border-radius: 9px; background: var(--card); color: var(--card-text); box-shadow: 0 4px 14px var(--shadow); text-align: left; }
    .admin-status-card:hover:not(:disabled) { background: var(--row-hover); }
    .admin-status-card strong { font-size: 21px; }
    .admin-dashboard-columns { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px; margin-bottom: 20px; }
    .admin-dashboard-panel { min-width: 0; padding: 18px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--card); color: var(--card-text); box-shadow: 0 5px 18px var(--shadow); }
    .admin-dashboard-panel h2 { margin: 0 0 16px; font-size: 18px; }
    .sales-chart { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); align-items: end; gap: 9px; min-height: 180px; padding-top: 12px; }
    .sales-chart-day { display: flex; flex-direction: column; justify-content: flex-end; align-items: center; height: 160px; gap: 7px; color: var(--muted); font-size: 11px; text-align: center; }
    .sales-chart-track { display: flex; align-items: flex-end; justify-content: center; width: 100%; height: 125px; }
    .sales-chart-bar { width: min(30px, 80%); min-height: 3px; border-radius: 5px 5px 0 0; background: var(--main); transform: scaleY(0); transform-origin: bottom; animation: barGrow .35s ease-out forwards; }
    .low-stock-list, .best-seller-list, .recent-order-list { display: grid; gap: 10px; }
    .low-stock-row, .best-seller-row, .recent-order-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding-bottom: 9px; border-bottom: 1px solid var(--border); }
    .low-stock-row:last-child, .best-seller-row:last-child, .recent-order-row:last-child { padding-bottom: 0; border-bottom: 0; }
    .low-stock-badge { padding: 4px 8px; border-radius: 99px; background: var(--error-bg); color: var(--error-text); font-size: 12px; font-weight: 700; white-space: nowrap; }
    .best-seller-info { flex: 1; min-width: 0; }
    .best-seller-bar { display: block; height: 6px; margin-top: 6px; border-radius: 99px; background: var(--accent-soft); }
    .best-seller-bar span { display: block; height: 100%; border-radius: inherit; background: var(--main); }
    .recent-order-row { align-items: flex-start; }
    .recent-order-detail { display: grid; gap: 3px; min-width: 0; }
    .recent-order-detail small { color: var(--muted); }
    .recent-order-side { display: grid; justify-items: end; gap: 6px; white-space: nowrap; }
    .admin-quick-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
    .admin-dashboard-message { margin-bottom: 16px; }
    #admin-dashboard-content > * { animation: riseIn var(--speed) ease-out both; }
    #admin-dashboard-content > :nth-child(2) { animation-delay: .05s; }
    #admin-dashboard-content > :nth-child(3) { animation-delay: .1s; }
    #admin-dashboard-content > :nth-child(4) { animation-delay: .15s; }
    #admin-dashboard-content > :nth-child(5) { animation-delay: .2s; }
    #admin-dashboard-content > :nth-child(6) { animation-delay: .25s; }
    .inventory-form { display: grid; grid-template-columns: repeat(5, minmax(110px, 1fr)) auto; gap: 10px; align-items: end; margin: 16px 0 20px; padding: 16px; border: 1px solid var(--border); border-radius: 9px; background: var(--soft-bg); }
    .inventory-form label { margin: 0; color: var(--muted); font-size: 13px; }
    .inventory-form input, .inventory-form select { display: block; margin-top: 6px; color: var(--card-text); }
    .inventory-form-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: end; min-width: 0; }
    .inventory-form-actions button { white-space: nowrap; padding: 10px 13px; }
    .inventory-action { margin-right: 6px; padding: 7px 10px; background: var(--main); }
    .inventory-action:hover:not(:disabled) { background: var(--main-hover); }
    .inventory-action.delete { background: var(--danger); }
    .inventory-action.delete:hover:not(:disabled) { background: var(--danger-hover); }
    .mobile-sidebar-shade { display: none; padding: 0; border-radius: 0; background: var(--modal-overlay); }
    .terms-popup-overlay:not(.hidden), .order-editor-overlay:not(.hidden), .account-editor-overlay:not(.hidden) { animation: overlayIn .2s ease-out both; }
    .terms-popup-panel, .order-editor-panel, .account-editor-panel { animation: modalIn var(--speed) ease-out both; }
    .terms-popup-overlay.closing, .order-editor-overlay.closing, .account-editor-overlay.closing { animation: overlayOut .2s ease-in both; }
    .terms-popup-overlay.closing .terms-popup-panel, .order-editor-overlay.closing .order-editor-panel, .account-editor-overlay.closing .account-editor-panel { animation: modalOut .2s ease-in both; }
    .toast-region { position: fixed; top: 16px; right: 16px; z-index: 20; display: grid; gap: 8px; width: min(360px, calc(100vw - 32px)); }
    .toast { padding: 12px 15px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--card); color: var(--card-text); box-shadow: 0 8px 22px var(--shadow); animation: slideIn var(--speed) ease-out both; }
    .toast.leaving { animation: fadeOut .2s ease-in both; }
    .toast.success { background: var(--success-bg); color: var(--success-text); }
    .toast.error { background: var(--error-bg); color: var(--error-text); }
    .loading-message { animation: pulse .35s ease-in-out infinite alternate; }
    @keyframes riseIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    @keyframes overlayIn { from { opacity: 0; } to { opacity: 1; } }
    @keyframes overlayOut { from { opacity: 1; } to { opacity: 0; } }
    @keyframes modalIn { from { opacity: 0; transform: scale(.95); } to { opacity: 1; transform: scale(1); } }
    @keyframes modalOut { from { opacity: 1; transform: scale(1); } to { opacity: 0; transform: scale(.95); } }
    @keyframes slideIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes fadeOut { to { opacity: 0; transform: translateY(-6px); } }
    @keyframes barGrow { to { transform: scaleY(1); } }
    @keyframes pulse { to { opacity: .55; } }
    @media (prefers-reduced-motion: reduce) {
      *, *::before, *::after { scroll-behavior: auto !important; animation-duration: .01ms !important; animation-iteration-count: 1 !important; transition-duration: .01ms !important; }
    }
    @media (max-width: 700px) {
      .sidebar { width: 270px; transform: translateX(-100%); transition: transform .2s; }
      body.sidebar-open .sidebar { transform: translateX(0); }
      .dashboard-content { margin-left: 0; padding: 0 16px 22px; }
      .dashboard-topbar { min-height: 48px; margin: 0 -16px 20px; padding: 0 16px; }
      .dashboard-topbar h1 { font-size: 20px; }
      .mobile-menu-button { display: inline-block; padding: 9px 12px; }
      body.sidebar-open .mobile-sidebar-shade { display: block; position: fixed; inset: 0; z-index: 4; background: var(--modal-overlay); }
      .dashboard-card { padding: 18px; }
      .menu-layout { grid-template-columns: 1fr; }
      .order-summary { position: static; }
      .menu-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .dashboard-stats { gap: 9px; }
      .dashboard-stat { padding: 13px; }
      .inventory-form { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .inventory-form-actions { grid-column: 1 / -1; }
    }
    @media (max-width: 1100px) {
      #admin-orders-list { overflow-x: visible; }
      .admin-orders-table, .admin-orders-table tbody, .admin-orders-table tr, .admin-orders-table td { display: block; width: 100%; }
      .admin-orders-table thead { display: none; }
      .admin-orders-table tbody tr { display: grid; grid-template-columns: 1fr 1fr; gap: 0 14px; margin-bottom: 12px; padding: 8px 12px; border: 1px solid var(--border); border-radius: 8px; background: var(--card); }
      .admin-orders-table tbody td { display: flex; width: auto; justify-content: space-between; gap: 10px; align-items: flex-start; padding: 8px 0; border-bottom: 1px solid var(--border); }
      .admin-orders-table tbody td::before { content: attr(data-label); flex: 0 0 auto; color: var(--muted); font-size: 12px; font-weight: 700; }
      .admin-orders-table tbody td:nth-child(4) { grid-column: 1 / -1; }
      .admin-orders-table tbody td:nth-child(9) { justify-content: flex-end; border-bottom: 0; }
      .admin-orders-table tbody td:nth-child(9)::before { margin-right: auto; }
    }
    @media (min-width: 701px) and (max-width: 1100px) {
      .inventory-form { grid-template-columns: repeat(3, minmax(0, 1fr)); }
      .inventory-form-actions { grid-column: 1 / -1; }
    }
    @media (max-width: 800px) { .profile-layout { grid-template-columns: 1fr; } }
    @media (max-width: 520px) { main { margin: 20px auto; } .card { padding: 18px; } .auth { padding: 21px; } .signup-grid { grid-template-columns: 1fr; gap: 0; } .topline { align-items: flex-start; } .profile { grid-template-columns: 1fr; gap: 4px; } .profile strong:not(:first-child) { margin-top: 8px; } .search-tools { align-items: stretch; flex-direction: column; } .search-tools > input, .search-tools > select { width: 100%; min-width: 100%; } .search-date-fields { flex-direction: column; align-items: stretch; } .search-date-fields input[type="date"] { width: 100%; min-width: 135px; } .menu-cards { grid-template-columns: 1fr; } .dashboard-stats { grid-template-columns: 1fr; } .welcome-line { align-items: flex-start; flex-direction: column; } .order-editor-fields { grid-template-columns: 1fr; } .order-add-row { grid-template-columns: 1fr 85px; } .order-add-row button { grid-column: 1 / -1; } .order-editor-heading, .order-editor-content { padding: 16px; } .order-editor-actions { padding: 12px 16px; } .inventory-form { grid-template-columns: 1fr; } .inventory-form-actions { grid-column: auto; } }
  </style>
</head>
<body>
  <header><h1>KantEase</h1></header>
  <main>
    <section class="card auth hidden" data-view="login">
      <div class="brand-line"><span class="brand-mark">🍴</span><strong>KantEase</strong></div>
      <div class="auth-heading"><h2 class="auth-title">Welcome back</h2><button class="theme-toggle" type="button"></button></div>
      <p class="auth-subtitle">Log in to order your canteen favorites.</p>
      <div id="login-message" aria-live="polite"></div>
      <form id="login-form">
        <label for="login-id">User ID or email</label>
        <input id="login-id" name="user_id" autocomplete="username" required>
        <label for="login-password">Password</label>
        <input id="login-password" name="password" type="password" autocomplete="current-password" required>
        <button class="full" type="submit">Log in</button>
      </form>
      <hr class="auth-divider">
      <p class="auth-footer">No account yet? <a href="/signup">Create an account</a></p>
      <p class="auth-page-footer">© 2026 KantEase · Canteen ordering made easy</p>
    </section>
    <section class="card auth hidden" data-view="signup">
      <div class="brand-line"><span class="brand-mark">🍴</span><strong>KantEase</strong></div>
      <div class="auth-heading"><h2 class="auth-title">Create your account</h2><button class="theme-toggle" type="button"></button></div>
      <p class="auth-subtitle">Sign up to explore the canteen menu and order ahead.</p>
      <div id="signup-message" aria-live="polite"></div>
      <form id="signup-form">
        <div class="signup-grid">
          <div><label for="full-name">Full name</label><input id="full-name" name="full_name" maxlength="120" autocomplete="name" required></div>
          <div><label for="email">Email</label><input id="email" name="email" type="email" maxlength="254" autocomplete="email" required></div>
          <div><label for="signup-password">Password (at least 8 characters)</label><input id="signup-password" name="password" type="password" minlength="8" maxlength="128" autocomplete="new-password" required></div>
          <div><label for="confirm-password">Confirm password</label><input id="confirm-password" name="confirm_password" type="password" minlength="8" maxlength="128" autocomplete="new-password" required></div>
          <div><label for="role">Role</label><select id="role" name="role" required><option value="student">Student</option><option value="admin">Admin</option></select></div>
          <div id="admin-code-wrap" class="hidden"><label for="admin-code">Admin Code</label><input id="admin-code" name="admin_code" type="password" autocomplete="off"></div>
        </div>
        <label class="check"><input id="terms" name="terms" type="checkbox"> <span>I agree to the <a id="terms-link" href="#">Terms and Conditions</a></span></label>
        <button id="create-account" class="full" type="submit" disabled>Create Account</button>
      </form>
      <div id="terms-popup" class="terms-popup-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="terms-popup-title">
        <div class="terms-popup-panel">
          <h2 id="terms-popup-title">Terms and Conditions</h2>
          <div class="terms-popup-content">
            <p><strong>1. Acceptance.</strong> By creating an account in KantEase, you agree to follow these terms. If you do not agree, you cannot create an account.</p>
            <p><strong>2. True Information.</strong> You must give correct information when signing up, including your full name and email. Fake or borrowed identities are not allowed.</p>
            <p><strong>3. Account Security.</strong> Keep your password and your User ID private. You are responsible for everything done using your account. Do not share it with anyone.</p>
            <p><strong>4. Ordering.</strong> Students can order only the items that are available and in stock. Orders are for pick-up at the canteen only. There is no delivery.</p>
            <p><strong>5. Payment.</strong> The system only shows and records the amount to be paid. It does not process online or card payments. Please pay at the canteen counter.</p>
            <p><strong>6. Admin Accounts.</strong> Admin accounts are only for authorized canteen staff. Admins must use their access only to manage the menu, stock, orders, and reports.</p>
            <p><strong>7. Proper Use.</strong> Do not try to hack, break, or misuse the system, or access another person's account. Placing fake or prank orders is not allowed.</p>
            <p><strong>8. Data Privacy.</strong> Your name, email, and order history are stored only in the school's canteen database and are used only for the operation of the canteen system. They are not sold or shared outside the school.</p>
            <p><strong>9. Account Removal.</strong> The admin may suspend or delete accounts that break these terms.</p>
            <p><strong>10. Changes.</strong> These terms may be updated when the system changes. Continuing to use the system means you accept the updated terms.</p>
          </div>
          <button id="terms-popup-close" class="terms-popup-close" type="button">Close</button>
        </div>
      </div>
      <hr class="auth-divider">
      <p class="auth-footer">Already have an account? <a href="/">Log in</a></p>
      <p class="auth-page-footer">© 2026 KantEase · Canteen ordering made easy</p>
    </section>
    <section class="hidden" data-view="student">
      <div class="dashboard-layout">
        <aside class="sidebar">
          <h1 class="sidebar-brand">KantEase</h1>
          <nav class="sidebar-menu">
            <button type="button" data-section-link="dashboard">🏠 Dashboard</button>
            <button type="button" data-section-link="menu">🍽️ Menu and Order</button>
            <button type="button" data-section-link="orders">🧾 My Orders</button>
            <button type="button" data-section-link="profile">👤 My Profile</button>
          </nav>
          <div class="sidebar-user">
            <p class="sidebar-name" id="student-nav-name">Student</p>
            <p class="sidebar-id" id="student-nav-id"></p>
            <span class="role-badge">Student</span>
            <button class="logout sidebar-logout" type="button">Log out</button>
          </div>
        </aside>
        <button class="mobile-sidebar-shade" type="button" aria-label="Close menu"></button>
        <div class="dashboard-content">
          <div class="dashboard-topbar">
            <button class="mobile-menu-button" type="button">Menu</button>
            <h1 id="student-section-title">Dashboard</h1>
            <button class="theme-toggle" type="button"></button>
          </div>
          <section class="section active" data-section="dashboard">
            <div id="student-dashboard-message" aria-live="polite"></div>
            <div class="welcome-line"><div><h2 id="student-welcome">Welcome!</h2><p class="dashboard-note">Here is a quick look at your canteen activity.</p></div></div>
            <div class="pickup-banner"><span class="pickup-icon">🛍️</span><div><strong>Ready when you are</strong><p>Place your order and pick it up at the canteen counter.</p></div></div>
            <div class="dashboard-stats">
              <div class="dashboard-stat"><span>Total orders</span><strong id="student-order-count">0</strong><small>Your orders so far</small></div>
              <div class="dashboard-stat"><span>Total spent</span><strong id="student-total-spent">₱0.00</strong><small>Recorded order totals</small></div>
              <div class="dashboard-stat"><span>Last order</span><strong id="student-last-order">—</strong><small>Most recent activity</small></div>
            </div>
            <div class="dashboard-card latest-card">
              <div class="latest-heading"><h2>Recent orders</h2><button type="button" data-section-link="orders">View all →</button></div>
              <div id="student-recent-orders" class="table-wrap"></div>
            </div>
          </section>
          <section class="section" data-section="menu">
            <p class="section-intro">Choose available items, add them to your cart, then confirm your pick-up order.</p>
            <div class="menu-layout">
              <div class="dashboard-card">
                <h2>Today's menu</h2>
                <div id="menu-search"></div>
                <div id="menu-categories" class="category-chips"></div>
                <div id="menu-list" class="menu-cards"></div>
              </div>
              <div class="dashboard-card order-summary">
                <h2>Your order</h2>
                <p class="pickup-note">🛍️ Pick up your order at the canteen counter. Pay when you arrive.</p>
                <div id="order-message" aria-live="polite"></div>
                <div id="student-cart" class="cart-list"></div>
                <div class="cart-total"><strong>Total</strong><strong id="student-cart-total">₱0.00</strong></div>
                <button id="confirm-cart-order" class="full" type="button" disabled>Confirm Order</button>
              </div>
            </div>
          </section>
          <section class="section" data-section="orders">
            <p class="section-intro">View the items you have ordered and their recorded totals.</p>
            <div class="dashboard-card section-table-card">
              <div class="latest-heading"><h2>My orders</h2></div>
              <div id="student-orders-message" aria-live="polite"></div>
              <div id="student-orders-search"></div>
              <div id="my-orders" class="table-wrap"></div>
            </div>
          </section>
          <section class="section" data-section="profile">
            <div class="dashboard-card">
              <h2>My Profile</h2>
              <div class="profile-layout">
                <div id="student-profile" class="profile"></div>
                <form class="profile-edit-form" data-profile-edit-form>
                  <h3>Edit Profile</h3>
                  <label>Full Name<input type="text" data-profile-name maxlength="120" required></label>
                  <label>Email Address<input type="email" data-profile-email maxlength="254" required></label>
                  <button type="submit">Save Changes</button>
                  <div data-profile-message aria-live="polite"></div>
                </form>
              </div>
            </div>
            <div class="dashboard-card password-card">
              <h2>Change Password</h2>
              <form class="password-form" data-password-form="student">
                <label>Current Password<input type="password" data-password-current required></label>
                <label>New Password<input type="password" data-password-new minlength="8" maxlength="128" required></label>
                <label>Confirm New Password<input type="password" data-password-confirm minlength="8" maxlength="128" required></label>
                <button type="submit">Save Password</button>
                <div class="password-message" aria-live="polite"></div>
              </form>
            </div>
          </section>
        </div>
      </div>
    </section>
    <section class="hidden" data-view="admin">
      <div class="dashboard-layout">
        <aside class="sidebar">
          <h1 class="sidebar-brand">KantEase</h1>
          <nav class="sidebar-menu">
            <button type="button" data-section-link="dashboard">🏠 Dashboard</button>
            <button type="button" data-section-link="accounts">👥 Accounts</button>
            <button type="button" data-section-link="inventory">📦 Inventory</button>
            <button type="button" data-section-link="orders">🧾 Orders</button>
            <button type="button" data-section-link="sales">📊 Sales Report</button>
            <button type="button" data-section-link="profile">👤 Profile</button>
          </nav>
          <div class="sidebar-user">
            <p class="sidebar-name" id="admin-nav-name">Admin</p>
            <p class="sidebar-id" id="admin-nav-id"></p>
            <span class="role-badge">Admin</span>
            <button class="logout sidebar-logout" type="button">Log out</button>
          </div>
        </aside>
        <button class="mobile-sidebar-shade" type="button" aria-label="Close menu"></button>
        <div class="dashboard-content">
          <div class="dashboard-topbar">
            <button class="mobile-menu-button" type="button">Menu</button>
            <h1 id="admin-section-title">Dashboard</h1>
            <button class="theme-toggle" type="button"></button>
          </div>
          <section class="section active" data-section="dashboard">
            <div id="admin-dashboard-message" class="admin-dashboard-message" aria-live="polite"></div>
            <div id="admin-dashboard-content">
              <div class="welcome-line">
                <div><h2 id="admin-welcome">Welcome back</h2><p class="dashboard-note" id="admin-dashboard-date"></p></div>
              </div>
              <div class="admin-dashboard-grid">
                <div class="admin-summary-card"><span class="admin-summary-icon">🎓</span><div><span>Total Students</span><strong id="dash-students">0</strong></div></div>
                <div class="admin-summary-card"><span class="admin-summary-icon">🧑‍💼</span><div><span>Total Admins</span><strong id="dash-admins">0</strong></div></div>
                <div class="admin-summary-card"><span class="admin-summary-icon">🧾</span><div><span>Today's Orders</span><strong id="dash-orders-today">0</strong></div></div>
                <div class="admin-summary-card"><span class="admin-summary-icon">💰</span><div><span>Today's Sales</span><strong id="dash-sales-today">₱0.00</strong></div></div>
              </div>
              <div class="admin-status-row">
                <button class="admin-status-card" type="button" data-status-filter="Pending"><span>🟠 Pending</span><strong id="dash-pending">0</strong></button>
                <button class="admin-status-card" type="button" data-status-filter="Preparing"><span>🔵 Preparing</span><strong id="dash-preparing">0</strong></button>
                <button class="admin-status-card" type="button" data-status-filter="Ready"><span>🟢 Ready</span><strong id="dash-ready">0</strong></button>
                <button class="admin-status-card" type="button" data-status-filter="Completed"><span>⚪ Completed</span><strong id="dash-completed">0</strong></button>
              </div>
              <div class="admin-dashboard-columns">
                <div class="admin-dashboard-panel"><h2>Sales of the last 7 days</h2><div id="dashboard-sales-chart" class="sales-chart"></div></div>
                <div class="admin-dashboard-panel"><h2>Low Stock Alert</h2><div id="dashboard-low-stock" class="low-stock-list"></div></div>
              </div>
              <div class="admin-dashboard-columns">
                <div class="admin-dashboard-panel"><h2>Best Sellers</h2><div id="dashboard-best-sellers" class="best-seller-list"></div></div>
                <div class="admin-dashboard-panel"><h2>Recent Orders</h2><div id="dashboard-recent-orders" class="recent-order-list"></div></div>
              </div>
              <div class="admin-quick-actions">
                <button type="button" data-section-link="inventory">➕ Add Item</button>
                <button type="button" data-section-link="orders">🧾 View Orders</button>
                <button type="button" data-section-link="accounts">👥 View Accounts</button>
              </div>
            </div>
          </section>
          <section class="section" data-section="accounts">
            <div class="dashboard-card">
              <h2>Accounts</h2>
              <div class="toolbar"><label for="user-filter">Show</label><select id="user-filter"><option value="all">All</option><option value="student">Students</option><option value="admin">Admins</option></select></div>
              <div id="users-search"></div>
              <div id="users-message" aria-live="polite"></div>
              <div id="user-list" class="table-wrap"></div>
            </div>
          </section>
          <section class="section" data-section="inventory">
            <div class="dashboard-card">
              <h2>Inventory</h2>
              <div id="inventory-message" aria-live="polite"></div>
              <form id="inventory-form" class="inventory-form">
                <input id="inventory-id" type="hidden">
                <label for="inventory-name">Item name<input id="inventory-name" maxlength="120" required></label>
                <label for="inventory-price">Price (₱)<input id="inventory-price" type="number" min="0.01" step="0.01" required></label>
                <label for="inventory-category">Category<select id="inventory-category" required>
                  <option>Meals</option><option>Snacks</option><option>Drinks</option><option>Desserts</option><option>Others</option>
                </select></label>
                <label for="inventory-stock">Stock<input id="inventory-stock" type="number" min="0" step="1" required></label>
                <label for="inventory-low-stock">Low stock alert at<input id="inventory-low-stock" type="number" min="0" step="1" value="5" required></label>
                <div class="inventory-form-actions">
                  <button id="inventory-save" type="submit">Add Item</button>
                  <button id="inventory-cancel" class="secondary hidden" type="button">Cancel Edit</button>
                </div>
              </form>
              <div id="inventory-search"></div>
              <div id="inventory-list" class="table-wrap"></div>
            </div>
          </section>
          <section class="section" data-section="orders">
            <div class="dashboard-card">
              <h2>Orders</h2>
              <div id="orders-message" aria-live="polite"></div>
              <div id="admin-orders-search"></div>
              <div class="export-row"><button type="button" id="export-orders">Export CSV</button></div>
              <div id="admin-orders-list" class="table-wrap"></div>
            </div>
          </section>
          <section class="section" data-section="sales">
            <div class="dashboard-card">
              <h2>Sales Report</h2>
              <div id="sales-message" aria-live="polite"></div>
              <div id="sales-search"></div>
              <div class="export-row"><button type="button" id="export-sales">Export CSV</button></div>
              <div id="sales-list" class="table-wrap"></div>
            </div>
          </section>
          <section class="section" data-section="profile">
            <div class="dashboard-card">
              <h2>My Profile</h2>
              <div class="profile-layout">
                <div id="admin-profile" class="profile"></div>
                <form class="profile-edit-form" data-profile-edit-form>
                  <h3>Edit Profile</h3>
                  <label>Full Name<input type="text" data-profile-name maxlength="120" required></label>
                  <label>Email Address<input type="email" data-profile-email maxlength="254" required></label>
                  <button type="submit">Save Changes</button>
                  <div data-profile-message aria-live="polite"></div>
                </form>
              </div>
            </div>
            <div class="dashboard-card password-card">
              <h2>Change Password</h2>
              <form class="password-form" data-password-form="admin">
                <label>Current Password<input type="password" data-password-current required></label>
                <label>New Password<input type="password" data-password-new minlength="8" maxlength="128" required></label>
                <label>Confirm New Password<input type="password" data-password-confirm minlength="8" maxlength="128" required></label>
                <button type="submit">Save Password</button>
                <div class="password-message" aria-live="polite"></div>
              </form>
            </div>
          </section>
        </div>
      </div>
    </section>
    <div id="order-editor" class="order-editor-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="order-editor-title">
      <div class="order-editor-panel">
        <div class="order-editor-heading">
          <h2 id="order-editor-title">Edit Order</h2>
          <p class="order-editor-meta" id="order-editor-meta"></p>
        </div>
        <div class="order-editor-content">
          <div id="order-editor-message" aria-live="polite"></div>
          <div class="order-editor-fields">
            <label>Status<select id="edit-order-status">
              <option value="Pending">Pending</option><option value="Preparing">Preparing</option>
              <option value="Ready">Ready for Pickup</option><option value="Completed">Completed</option>
              <option value="Cancelled">Cancelled</option>
            </select></label>
            <label>Payment<select id="edit-order-payment"><option value="Unpaid">Unpaid</option><option value="Paid">Paid</option></select></label>
          </div>
          <div class="order-items" id="edit-order-items"></div>
          <div class="order-add-row" id="order-add-controls">
            <label>Add item<select id="add-order-food"></select></label>
            <label>Quantity<input id="add-order-quantity" type="number" min="1" max="100" value="1"></label>
            <button id="add-order-item" type="button">Add item</button>
          </div>
          <div class="order-editor-total"><strong>Total</strong><strong id="edit-order-total">₱0.00</strong></div>
          <div class="order-editor-note"><label for="edit-order-note">Note (optional)</label><input id="edit-order-note" maxlength="200"></div>
        </div>
        <div class="order-editor-actions">
          <button id="order-editor-cancel" class="secondary" type="button">Cancel</button>
          <button id="order-editor-save" type="button">Save Changes</button>
        </div>
      </div>
    </div>
    <div id="account-editor" class="account-editor-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="account-editor-title">
      <div class="account-editor-panel">
        <h2 id="account-editor-title">Edit Account</h2>
        <div id="account-editor-message" aria-live="polite"></div>
        <label for="account-edit-code">User ID</label>
        <input id="account-edit-code" readonly>
        <label for="account-edit-name">Full name</label>
        <input id="account-edit-name" maxlength="120" required>
        <label for="account-edit-email">Email</label>
        <input id="account-edit-email" type="email" maxlength="254" required>
        <label for="account-edit-role">Role</label>
        <select id="account-edit-role"><option value="student">Student</option><option value="admin">Admin</option></select>
        <label for="account-edit-password">New password (leave blank to keep current)</label>
        <input id="account-edit-password" type="password" minlength="8" maxlength="128" autocomplete="new-password">
        <div class="account-editor-actions">
          <button id="account-edit-cancel" class="secondary" type="button">Cancel</button>
          <button id="account-edit-save" type="button">Save</button>
        </div>
      </div>
    </div>
    <div id="toast-region" class="toast-region" aria-live="polite"></div>
  </main>
  <script>
    function setTheme(theme, save) {
      document.documentElement.setAttribute("data-theme", theme);
      document.body.setAttribute("data-theme", theme);
      document.querySelectorAll(".theme-toggle").forEach(function(button) {
        button.textContent = theme === "dark" ? "☀️ Light" : "🌙 Dark";
        button.setAttribute("aria-label", theme === "dark" ? "Switch to light mode" : "Switch to dark mode");
        button.setAttribute("title", theme === "dark" ? "Switch to light mode" : "Switch to dark mode");
      });
      if (save) {
        try { localStorage.setItem("kantease-theme", theme); } catch (error) {}
      }
    }

    setTheme(document.documentElement.getAttribute("data-theme") || "light", false);
    document.querySelectorAll(".theme-toggle").forEach(function(button) {
      button.addEventListener("click", function() {
        var current = document.documentElement.getAttribute("data-theme");
        setTheme(current === "dark" ? "light" : "dark", true);
      });
    });

    function showMessage(box, text, type) {
      box.textContent = text;
      box.className = text ? "message " + type + (type === "loading" ? " loading-message" : "") : "";
    }

    function showToast(text, type) {
      var toast = document.createElement("div");
      toast.className = "toast " + (type || "success");
      toast.textContent = text;
      document.getElementById("toast-region").appendChild(toast);
      setTimeout(function() {
        toast.classList.add("leaving");
        setTimeout(function() { toast.remove(); }, 220);
      }, 3000);
    }

    function animateNumber(element, value, money) {
      var target = Number(value) || 0;
      if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
        element.textContent = money ? "₱" + target.toFixed(2) : Math.round(target);
        return;
      }
      var start = performance.now();
      function update(now) {
        var progress = Math.min((now - start) / 600, 1);
        var amount = target * progress;
        element.textContent = money ? "₱" + amount.toFixed(2) : Math.round(amount);
        if (progress < 1) requestAnimationFrame(update);
      }
      requestAnimationFrame(update);
    }

    function openModal(modal) {
      clearTimeout(modal.closeTimer);
      modal.classList.remove("closing");
      modal.classList.remove("hidden");
    }

    function closeModal(modal) {
      modal.classList.add("closing");
      modal.closeTimer = setTimeout(function() {
        modal.classList.add("hidden");
        modal.classList.remove("closing");
      }, 220);
    }

    async function api(url, options) {
      var response = await fetch(url, options || {});
      var data = await response.json();
      if (response.status === 401) location.href = "/";
      if (!response.ok) throw new Error(data.error || "May naganap na error.");
      return data;
    }

    async function downloadCsv(url) {
      var response = await fetch(url);
      if (response.status === 401) location.href = "/";
      if (!response.ok) {
        var errorData = await response.json();
        throw new Error(errorData.error || "CSV export failed.");
      }
      var csv = await response.blob();
      var link = document.createElement("a");
      link.href = URL.createObjectURL(csv);
      link.download = response.headers.get("Content-Disposition").split("filename=")[1].replace(/"/g, "");
      document.body.appendChild(link);
      link.click();
      link.remove();
      setTimeout(function() { URL.revokeObjectURL(link.href); }, 1000);
      showToast("CSV download started.", "success");
    }

    document.querySelectorAll("[data-password-form]").forEach(function(form) {
      form.addEventListener("submit", async function(event) {
        event.preventDefault();
        var message = form.querySelector(".password-message");
        message.className = "password-message";
        message.textContent = "";
        try {
          await api("/api/change-password", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              current_password: form.querySelector("[data-password-current]").value,
              new_password: form.querySelector("[data-password-new]").value,
              confirm_password: form.querySelector("[data-password-confirm]").value
            })
          });
          form.reset();
          showToast("Password changed successfully.", "success");
        } catch (error) {
          message.className = "password-message error";
          message.textContent = error.message;
        }
      });
    });

    function showProfile(box, user) {
      box.textContent = "";
      [["User ID", user.user_code], ["Name", user.full_name], ["Email", user.email], ["Role", user.role]].forEach(function(item) {
        var label = document.createElement("strong");
        label.textContent = item[0];
        var value = document.createElement("span");
        value.textContent = item[1];
        box.append(label, value);
      });
      if (user.created_at) {
        var joined = document.createElement("strong");
        joined.textContent = "Date joined";
        var date = document.createElement("span");
        date.textContent = displayDate(user.created_at);
        box.append(joined, date);
      }
      var editForm = document.querySelector('[data-view="' + view + '"] [data-profile-edit-form]');
      if (editForm) {
        editForm.querySelector("[data-profile-name]").value = user.full_name;
        editForm.querySelector("[data-profile-email]").value = user.email;
      }
    }

    var currentUser = null;
    var activeSection = "";
    var studentCart = [];
    var availableMenuItems = [];
    var activeMenuCategory = "All";
    var menuCategories = ["Meals", "Snacks", "Drinks", "Desserts", "Others"];
    document.querySelectorAll("[data-profile-edit-form]").forEach(function(form) {
      form.addEventListener("submit", async function(event) {
        event.preventDefault();
        var message = form.querySelector("[data-profile-message]");
        var button = form.querySelector('button[type="submit"]');
        showMessage(message, "", "");
        button.disabled = true;
        try {
          currentUser = await api("/api/me", {
            method: "PUT",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              full_name: form.querySelector("[data-profile-name]").value,
              email: form.querySelector("[data-profile-email]").value
            })
          });
          showProfile(document.getElementById(view + "-profile"), currentUser);
          document.getElementById(view + "-nav-name").textContent = currentUser.full_name;
          if (view === "student") document.getElementById("student-welcome").textContent = "Welcome, " + currentUser.full_name + "!";
          if (view === "admin") document.getElementById("admin-welcome").textContent = "Welcome back, " + currentUser.full_name;
          showMessage(message, "Profile updated successfully.", "success");
        } catch (error) {
          showMessage(message, error.message, "error");
        } finally {
          button.disabled = false;
        }
      });
    });
    var sectionTitles = {
      student: { dashboard: "Dashboard", menu: "Menu and Order", orders: "My Orders", profile: "My Profile" },
      admin: { dashboard: "Dashboard", accounts: "Accounts", inventory: "Inventory", orders: "Orders", sales: "Sales Report", profile: "Profile" }
    };

    function loadSectionData(name) {
      if (view === "admin" && name === "dashboard") {
        loadDashboard();
      } else if (view === "student" && name === "dashboard") {
        loadStudentDashboard();
      } else if (view === "student" && name === "menu") {
        loadMenu();
      } else if (view === "student" && name === "orders") {
        loadOrders();
      } else if (name === "profile" && currentUser) {
        showProfile(document.getElementById(view + "-profile"), currentUser);
      } else if (view === "admin" && name === "accounts") {
        loadUsers();
      } else if (view === "admin" && name === "inventory") {
        loadInventory().catch(function(error) { showAdminSearchError(error, name); });
      } else if (view === "admin" && name === "orders") {
        loadAdminOrders().catch(function(error) { showAdminSearchError(error, name); });
      } else if (view === "admin" && name === "sales") {
        loadSales().catch(function(error) { showAdminSearchError(error, name); });
      }
    }

    function showSection(name, updateHash) {
      var titles = sectionTitles[view];
      if (!titles || !Object.prototype.hasOwnProperty.call(titles, name)) name = "dashboard";
      if (updateHash && location.hash !== "#" + name) location.hash = name;
      if (activeSection === name) {
        if (view === "admin" && name === "dashboard") loadDashboard();
        return;
      }
      activeSection = name;
      document.querySelectorAll('[data-view="' + view + '"] .section').forEach(function(section) {
        section.classList.toggle("active", section.getAttribute("data-section") === name);
      });
      document.querySelectorAll('[data-view="' + view + '"] [data-section-link]').forEach(function(button) {
        button.classList.toggle("selected", button.getAttribute("data-section-link") === name);
      });
      document.getElementById(view + "-section-title").textContent = titles[name];
      loadSectionData(name);
    }

    var searchTimers = {};

    function installSearchBar(id, section, fields, useDates, reload) {
      var box = document.getElementById(id);
      var row = document.createElement("div");
      row.className = "search-tools";
      var input = document.createElement("input");
      input.type = "search";
      input.placeholder = "Search...";
      input.setAttribute("aria-label", "Search " + section);
      var select = document.createElement("select");
      select.setAttribute("aria-label", "Search field");
      [["all", "All fields"]].concat(fields).forEach(function(option) {
        var choice = document.createElement("option");
        choice.value = option[0];
        choice.textContent = option[1];
        select.appendChild(choice);
      });
      row.appendChild(input);
      row.appendChild(select);
      var from = null;
      var to = null;
      if (useDates) {
        var dateGroup = document.createElement("div");
        dateGroup.className = "search-date-group";
        var dateHeading = document.createElement("span");
        dateHeading.className = "search-date-heading";
        dateHeading.textContent = "Date range";
        dateGroup.appendChild(dateHeading);
        var dateFields = document.createElement("div");
        dateFields.className = "search-date-fields";
        from = document.createElement("input");
        from.type = "date";
        from.id = section + "-date-from";
        var fromLabel = document.createElement("label");
        fromLabel.htmlFor = from.id;
        fromLabel.appendChild(document.createTextNode("From"));
        fromLabel.appendChild(from);
        to = document.createElement("input");
        to.type = "date";
        to.id = section + "-date-to";
        var toLabel = document.createElement("label");
        toLabel.htmlFor = to.id;
        toLabel.appendChild(document.createTextNode("To"));
        toLabel.appendChild(to);
        dateFields.appendChild(fromLabel);
        dateFields.appendChild(toLabel);
        dateGroup.appendChild(dateFields);
        row.appendChild(dateGroup);
      }
      var clear = document.createElement("button");
      clear.type = "button";
      clear.className = "clear-search";
      clear.textContent = "Clear";
      row.appendChild(clear);
      var count = document.createElement("div");
      count.className = "search-count";
      box.textContent = "";
      box.appendChild(row);
      box.appendChild(count);

      function changed(wait) {
        clearTimeout(searchTimers[section]);
        searchTimers[section] = setTimeout(reload, wait);
      }
      input.addEventListener("input", function() { changed(300); });
      input.addEventListener("keydown", function(event) {
        if (event.key === "Enter") {
          event.preventDefault();
          changed(0);
        }
      });
      select.addEventListener("change", function() { changed(0); });
      if (useDates) {
        from.addEventListener("change", function() { changed(0); });
        to.addEventListener("change", function() { changed(0); });
      }
      clear.addEventListener("click", function() {
        clearTimeout(searchTimers[section]);
        input.value = "";
        select.value = "all";
        if (useDates) { from.value = ""; to.value = ""; }
        reload();
      });
    }

    function searchUrl(path, section) {
      var box = document.getElementById(section + "-search");
      var row = box.querySelector(".search-tools");
      var inputs = row.querySelectorAll("input");
      var select = row.querySelector("select");
      var params = new URLSearchParams();
      params.set("field", select.value);
      params.set("q", inputs[0].value);
      if (inputs.length > 1) {
        params.set("from", inputs[1].value);
        params.set("to", inputs[2].value);
      }
      return path + (path.indexOf("?") >= 0 ? "&" : "?") + params.toString();
    }

    function showSearchCount(section, amount) {
      document.getElementById(section + "-search").querySelector(".search-count").textContent =
        amount + (amount === 1 ? " result" : " results");
    }

    function drawTable(box, headings, rows) {
      box.textContent = "";
      var table = document.createElement("table");
      var head = document.createElement("tr");
      headings.forEach(function(text) {
        var cell = document.createElement("th");
        cell.textContent = text;
        head.appendChild(cell);
      });
      var thead = document.createElement("thead");
      thead.appendChild(head);
      table.appendChild(thead);
      var body = document.createElement("tbody");
      if (rows.length === 0) {
        var emptyRow = document.createElement("tr");
        var emptyCell = document.createElement("td");
        emptyCell.colSpan = headings.length;
        emptyCell.textContent = "No results found";
        emptyRow.appendChild(emptyCell);
        body.appendChild(emptyRow);
      } else {
        rows.forEach(function(values) {
          if (values.nodeType) {
            body.appendChild(values);
            return;
          }
          var row = document.createElement("tr");
          values.forEach(function(value) {
            var cell = document.createElement("td");
            cell.textContent = value == null ? "" : value;
            row.appendChild(cell);
          });
          body.appendChild(row);
        });
      }
      table.appendChild(body);
      box.appendChild(table);
    }

    function displayDate(value) {
      return value ? new Date(value).toLocaleString() : "";
    }

    function badgeCell(row, text, className) {
      var cell = document.createElement("td");
      var badge = document.createElement("span");
      badge.className = "order-badge " + className;
      badge.textContent = text;
      cell.appendChild(badge);
      row.appendChild(cell);
    }

    function statusBadgeClass(status) {
      return {
        Pending: "status-pending",
        Preparing: "status-preparing",
        Ready: "status-ready",
        Completed: "status-completed",
        Cancelled: "status-cancelled"
      }[status] || "status-pending";
    }

    function drawStudentOrders(box, orders, limit) {
      var headings = ["Order No.", "Item", "Total (₱)", "Payment", "Status", "Date"];
      var selected = typeof limit === "number" ? orders.slice(0, limit) : orders;
      var rows = [];
      selected.forEach(function(order) {
        var row = document.createElement("tr");
        [order.order_number, order.item_name, "₱" + Number(order.total_amount).toFixed(2)].forEach(function(value) {
          var td = document.createElement("td");
          td.textContent = value;
          row.appendChild(td);
        });
        badgeCell(row, order.payment_status, order.payment_status === "Paid" ? "payment-paid" : "payment-unpaid");
        var label = order.status === "Ready" ? "Ready for Pickup" : order.status;
        badgeCell(row, label, statusBadgeClass(order.status));
        var date = document.createElement("td");
        date.textContent = displayDate(order.order_date);
        row.appendChild(date);
        rows.push(row);
      });
      drawTable(box, headings, rows);
    }

    function localDateKey(date) {
      return date.getFullYear() + "-" + String(date.getMonth() + 1).padStart(2, "0") +
        "-" + String(date.getDate()).padStart(2, "0");
    }

    function renderDashboardChart(rows) {
      var box = document.getElementById("dashboard-sales-chart");
      var byDate = {};
      rows.forEach(function(row) {
        var key = row.sale_date instanceof Date ? localDateKey(row.sale_date) : String(row.sale_date).slice(0, 10);
        byDate[key] = Number(row.total_amount);
      });
      var days = [];
      var max = 0;
      for (var i = 6; i >= 0; i--) {
        var date = new Date();
        date.setHours(12, 0, 0, 0);
        date.setDate(date.getDate() - i);
        var key = localDateKey(date);
        var amount = byDate[key] || 0;
        max = Math.max(max, amount);
        days.push({ date: date, key: key, amount: amount });
      }
      box.textContent = "";
      days.forEach(function(day) {
        var item = document.createElement("div");
        item.className = "sales-chart-day";
        var track = document.createElement("div");
        track.className = "sales-chart-track";
        var bar = document.createElement("div");
        bar.className = "sales-chart-bar";
        bar.style.height = (day.amount ? Math.max(5, day.amount / (max || 1) * 100) : 2) + "%";
        bar.title = day.key + ": ₱" + day.amount.toFixed(2);
        track.appendChild(bar);
        var label = document.createElement("span");
        label.textContent = day.date.toLocaleDateString(undefined, { weekday: "short" });
        item.append(track, label);
        box.appendChild(item);
      });
    }

    function renderLowStock(items) {
      var box = document.getElementById("dashboard-low-stock");
      box.textContent = "";
      if (!items.length) {
        box.textContent = "All items are well stocked";
        return;
      }
      items.forEach(function(item) {
        var row = document.createElement("div");
        row.className = "low-stock-row";
        var name = document.createElement("span");
        name.textContent = item.name;
        var badge = document.createElement("span");
        badge.className = "low-stock-badge";
        badge.textContent = item.stock + " left";
        row.append(name, badge);
        box.appendChild(row);
      });
    }

    function renderBestSellers(items) {
      var box = document.getElementById("dashboard-best-sellers");
      box.textContent = "";
      if (!items.length) {
        box.textContent = "No sales this week yet.";
        return;
      }
      var max = Math.max.apply(null, items.map(function(item) { return Number(item.quantity_sold); }));
      items.forEach(function(item) {
        var row = document.createElement("div");
        row.className = "best-seller-row";
        var info = document.createElement("div");
        info.className = "best-seller-info";
        var name = document.createElement("strong");
        name.textContent = item.item_name;
        var track = document.createElement("span");
        track.className = "best-seller-bar";
        var fill = document.createElement("span");
        fill.style.width = Math.max(4, Number(item.quantity_sold) / max * 100) + "%";
        track.appendChild(fill);
        info.append(name, track);
        var count = document.createElement("span");
        count.textContent = item.quantity_sold + " sold";
        row.append(info, count);
        box.appendChild(row);
      });
    }

    function renderRecentOrders(orders) {
      var box = document.getElementById("dashboard-recent-orders");
      box.textContent = "";
      if (!orders.length) {
        box.textContent = "No orders yet.";
        return;
      }
      orders.forEach(function(order) {
        var row = document.createElement("div");
        row.className = "recent-order-row";
        var detail = document.createElement("div");
        detail.className = "recent-order-detail";
        var orderNo = document.createElement("strong");
        orderNo.textContent = "Order #" + order.order_number + " · " + order.student_name;
        var time = document.createElement("small");
        time.textContent = displayDate(order.order_date);
        detail.append(orderNo, time);
        var side = document.createElement("div");
        side.className = "recent-order-side";
        var total = document.createElement("strong");
        total.textContent = "₱" + Number(order.total_amount).toFixed(2);
        var badge = document.createElement("span");
        badge.className = "order-badge " + statusBadgeClass(order.status);
        badge.textContent = order.status;
        side.append(total, badge);
        row.append(detail, side);
        box.appendChild(row);
      });
    }

    async function loadDashboard() {
      var message = document.getElementById("admin-dashboard-message");
      var content = document.getElementById("admin-dashboard-content");
      showMessage(message, "Loading dashboard...", "loading");
      content.classList.add("hidden");
      try {
        var data = await api("/api/admin/dashboard");
        var now = new Date();
        document.getElementById("admin-dashboard-date").textContent =
          now.toLocaleDateString(undefined, { weekday: "long", year: "numeric", month: "long", day: "numeric" });
        animateNumber(document.getElementById("dash-students"), data.total_students, false);
        animateNumber(document.getElementById("dash-admins"), data.total_admins, false);
        animateNumber(document.getElementById("dash-orders-today"), data.today_orders, false);
        animateNumber(document.getElementById("dash-sales-today"), data.today_sales, true);
        animateNumber(document.getElementById("dash-pending"), data.status_counts.Pending, false);
        animateNumber(document.getElementById("dash-preparing"), data.status_counts.Preparing, false);
        animateNumber(document.getElementById("dash-ready"), data.status_counts.Ready, false);
        animateNumber(document.getElementById("dash-completed"), data.status_counts.Completed, false);
        renderDashboardChart(data.sales_week);
        renderLowStock(data.low_stock);
        renderBestSellers(data.best_sellers);
        renderRecentOrders(data.recent_orders);
        showMessage(message, "", "");
        content.classList.remove("hidden");
      } catch (error) {
        console.error("Admin dashboard load failed:", error);
        showMessage(message, "Could not load the dashboard. " + error.message, "error");
      }
    }

    async function loadStudentDashboard() {
      try {
        var orders = await api("/api/student-orders");
        var spent = orders.reduce(function(total, order) {
          return total + Number(order.total_amount);
        }, 0);
        document.getElementById("student-order-count").textContent = orders.length;
        document.getElementById("student-total-spent").textContent = "₱" + spent.toFixed(2);
        document.getElementById("student-last-order").textContent =
          orders.length ? displayDate(orders[0].order_date) : "No orders yet";
        drawStudentOrders(document.getElementById("student-recent-orders"), orders, 5);
        showMessage(document.getElementById("student-dashboard-message"), "", "");
      } catch (error) {
        console.error("Student dashboard load failed:", error);
        showMessage(document.getElementById("student-dashboard-message"), error.message, "error");
        document.getElementById("student-recent-orders").textContent = error.message;
      }
    }

    async function loadMenu() {
      try {
        availableMenuItems = await api(searchUrl("/api/menu", "menu"));
        var foundCategory = availableMenuItems.some(function(item) { return item.category === activeMenuCategory; });
        if (activeMenuCategory !== "All" && !foundCategory) activeMenuCategory = "All";
        drawMenuCategories();
        drawMenuItems();
        showSearchCount("menu", availableMenuItems.length);
      } catch (error) { showMessage(document.getElementById("order-message"), error.message, "error"); }
    }

    function drawMenuCategories() {
      var box = document.getElementById("menu-categories");
      box.textContent = "";
      var present = {};
      availableMenuItems.forEach(function(item) { present[item.category] = true; });
      var categories = ["All"].concat(menuCategories.filter(function(category) { return present[category]; }));
      categories.forEach(function(category) {
        var chip = document.createElement("button");
        chip.type = "button";
        chip.className = "category-chip" + (category === activeMenuCategory ? " selected" : "");
        chip.textContent = category;
        chip.addEventListener("click", function() {
          activeMenuCategory = category;
          drawMenuCategories();
          drawMenuItems();
        });
        box.appendChild(chip);
      });
    }

    function drawMenuItems() {
      var list = document.getElementById("menu-list");
      list.textContent = "";
      var items = availableMenuItems.filter(function(item) {
        return activeMenuCategory === "All" || item.category === activeMenuCategory;
      });
      if (items.length === 0) list.textContent = "No results found";
      items.forEach(function(item) {
          var stockCount = Number(item.stock);
          var isSoldOut = stockCount === 0;
          var card = document.createElement("div");
          card.className = "menu-item-card" + (isSoldOut ? " sold-out" : "");
          var art = document.createElement("div");
          art.className = "menu-item-art";
          art.textContent = "🍽️";
          var info = document.createElement("div");
          info.className = "menu-item-info";
          var name = document.createElement("h3");
          name.textContent = item.name;
          var category = document.createElement("span");
          category.className = "category-label";
          category.textContent = item.category;
          var bottom = document.createElement("div");
          bottom.className = "menu-item-bottom";
          var price = document.createElement("span");
          price.className = "menu-price";
          price.textContent = "₱" + Number(item.price).toFixed(2);
          var stock = document.createElement("span");
          var stockStatus = isSoldOut ? "Sold out" : stockCount <= Number(item.low_stock_level) ? "Low" : "Available";
          stock.className = "stock-badge" + (isSoldOut ? " sold-out" : stockStatus === "Low" ? " low" : "");
          stock.textContent = stockStatus;
          bottom.append(price, stock);
          var controls = document.createElement("div");
          controls.className = "menu-item-controls";
          var quantity = document.createElement("input");
          quantity.type = "number";
          quantity.min = "1";
          quantity.max = String(Math.max(stockCount, 1));
          quantity.value = "1";
          quantity.setAttribute("aria-label", "Quantity for " + item.name);
          quantity.disabled = isSoldOut;
          var add = document.createElement("button");
          add.type = "button";
          add.textContent = "Add to Cart";
          add.disabled = isSoldOut;
          add.addEventListener("click", function() {
            try {
              var amount = Number(quantity.value);
              if (!Number.isInteger(amount) || amount < 1 || amount > 100) {
                throw new Error("Enter a quantity from 1 to 100.");
              }
              addSelectedItemToCart(Number(item.id), amount);
              showMessage(document.getElementById("order-message"), "", "");
              showToast("Item added to cart.", "success");
            } catch (error) {
              showMessage(document.getElementById("order-message"), error.message, "error");
            }
          });
          controls.append(quantity, add);
          info.append(category, name, bottom, controls);
          card.append(art, info);
          list.appendChild(card);
      });
    }

    function drawStudentCart() {
      var box = document.getElementById("student-cart");
      box.textContent = "";
      if (!studentCart.length) {
        var empty = document.createElement("div");
        empty.className = "cart-empty";
        empty.textContent = "Your cart is empty.";
        box.appendChild(empty);
      }
      var total = 0;
      studentCart.forEach(function(item, index) {
        var subtotal = Number(item.price) * item.quantity;
        total += subtotal;
        var row = document.createElement("div");
        row.className = "cart-row";
        var name = document.createElement("span");
        name.className = "cart-item-name";
        name.textContent = item.name;
        var detail = document.createElement("span");
        detail.className = "cart-item-detail";
        detail.textContent = item.quantity + " × ₱" + Number(item.price).toFixed(2) +
          " = ₱" + subtotal.toFixed(2);
        var remove = document.createElement("button");
        remove.type = "button";
        remove.textContent = "Remove";
        remove.addEventListener("click", function() {
          studentCart.splice(index, 1);
          drawStudentCart();
        });
        row.append(name, detail, remove);
        box.appendChild(row);
      });
      document.getElementById("student-cart-total").textContent = "₱" + total.toFixed(2);
      document.getElementById("confirm-cart-order").disabled = studentCart.length === 0;
    }

    function addSelectedItemToCart(foodId, quantity) {
      var food = availableMenuItems.find(function(item) { return Number(item.id) === foodId; });
      if (!food) throw new Error("Choose an available menu item.");
      if (Number(food.stock) <= 0) throw new Error(food.name + " is sold out.");
      var existing = studentCart.find(function(item) { return Number(item.food_id) === foodId; });
      var nextQuantity = quantity + (existing ? existing.quantity : 0);
      if (nextQuantity > Number(food.stock)) throw new Error("Only " + food.stock + " of " + food.name + " are in stock.");
      if (!existing && studentCart.length >= 20) throw new Error("A cart can contain up to 20 different items.");
      if (existing) existing.quantity = nextQuantity;
      else studentCart.push({
        food_id: foodId, name: food.name, price: Number(food.price), quantity: quantity
      });
      drawStudentCart();
    }

    async function loadOrders() {
      try {
        var orders = await api(searchUrl("/api/student-orders", "student-orders"));
        drawStudentOrders(document.getElementById("my-orders"), orders);
        showSearchCount("student-orders", orders.length);
        showMessage(document.getElementById("student-orders-message"), "", "");
      } catch (error) {
        showMessage(document.getElementById("student-orders-message"), error.message, "error");
      }
    }

    async function loadUsers() {
      var message = document.getElementById("users-message");
      try {
        var filter = document.getElementById("user-filter").value;
        var users = await api(searchUrl("/api/users?role=" + encodeURIComponent(filter), "users"));
        var rows = users.map(function(user) {
          var row = document.createElement("tr");
          [user.user_code, user.full_name, user.email, user.role, displayDate(user.created_at)].forEach(function(text) {
            var cell = document.createElement("td");
            cell.textContent = text;
            row.appendChild(cell);
          });
          var action = document.createElement("td");
          action.className = "account-actions";
          var edit = document.createElement("button");
          edit.type = "button";
          edit.className = "account-edit";
          edit.textContent = "Edit";
          edit.addEventListener("click", function() { openAccountEditor(user); });
          action.appendChild(edit);
          if (user.role === "student") {
            var button = document.createElement("button");
            button.type = "button";
            button.textContent = "Delete";
            button.addEventListener("click", async function() {
              if (!confirm("Delete this student account?")) return;
              try {
                await api("/api/users/" + encodeURIComponent(user.user_code), { method: "DELETE" });
                showMessage(message, "", "");
                showToast("Student account deleted.", "success");
                loadUsers();
              } catch (error) { showMessage(message, error.message, "error"); }
            });
            action.appendChild(button);
          }
          row.appendChild(action);
          return row;
        });
        drawTable(document.getElementById("user-list"), ["User ID", "Full Name", "Email", "Role", "Date Created", ""], rows);
        showSearchCount("users", users.length);
        showMessage(message, "", "");
      } catch (error) { showMessage(message, error.message, "error"); }
    }

    var accountBeingEdited = null;

    function openAccountEditor(user) {
      accountBeingEdited = user;
      document.getElementById("account-edit-code").value = user.user_code;
      document.getElementById("account-edit-name").value = user.full_name;
      document.getElementById("account-edit-email").value = user.email;
      var role = document.getElementById("account-edit-role");
      role.value = user.role;
      role.disabled = currentUser && currentUser.user_code === user.user_code;
      document.getElementById("account-edit-password").value = "";
      showMessage(document.getElementById("account-editor-message"), "", "");
      openModal(document.getElementById("account-editor"));
    }

    function closeAccountEditor() {
      closeModal(document.getElementById("account-editor"));
      accountBeingEdited = null;
    }

    document.getElementById("account-edit-cancel").addEventListener("click", closeAccountEditor);
    document.getElementById("account-editor").addEventListener("click", function(event) {
      if (event.target === event.currentTarget) closeAccountEditor();
    });
    document.getElementById("account-edit-save").addEventListener("click", async function() {
      if (!accountBeingEdited) return;
      var name = document.getElementById("account-edit-name").value.trim();
      var email = document.getElementById("account-edit-email").value.trim();
      var password = document.getElementById("account-edit-password").value;
      if (!name || !email) {
        showMessage(document.getElementById("account-editor-message"), "Name and email are required.", "error");
        return;
      }
      var changes = {
        full_name: name,
        email: email,
        role: document.getElementById("account-edit-role").value
      };
      if (password) changes.new_password = password;
      try {
        var saved = await api("/api/users/" + encodeURIComponent(accountBeingEdited.user_code), {
          method: "PUT",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(changes)
        });
        closeAccountEditor();
        showToast(saved.message || "Account updated.", "success");
        await loadUsers();
      } catch (error) {
        showMessage(document.getElementById("account-editor-message"), error.message, "error");
        showToast(error.message, "error");
      }
    });

    var editingOrder = null;
    var editingItems = [];
    var editableFoods = [];

    function drawAdminOrders(box, orders) {
      var headings = ["Order No.", "Student ID", "Student Name", "Items", "Total (₱)", "Payment", "Status", "Date", "Actions"];
      var rows = [];
      orders.forEach(function(order) {
        var row = document.createElement("tr");
        [order.order_number, order.student_id, order.student_name, order.item_summary,
          "₱" + Number(order.total_amount).toFixed(2)].forEach(function(value) {
          var td = document.createElement("td");
          td.textContent = value == null ? "" : value;
          row.appendChild(td);
        });
        badgeCell(row, order.payment_status, order.payment_status === "Paid" ? "payment-paid" : "payment-unpaid");
        badgeCell(row, order.status === "Ready" ? "Ready" : order.status, statusBadgeClass(order.status));
        var date = document.createElement("td");
        date.textContent = displayDate(order.order_date);
        row.appendChild(date);
        var action = document.createElement("td");
        var button = document.createElement("button");
        button.className = "order-action";
        button.type = "button";
        var locked = order.status === "Completed" || order.status === "Cancelled" || Number(order.has_unlinked_items) === 1;
        button.textContent = locked ? "View" : "Edit";
        button.addEventListener("click", function() {
          openOrderEditor(order.order_number);
        });
        action.appendChild(button);
        row.appendChild(action);
        rows.push(row);
      });
      drawTable(box, headings, rows);
      var table = box.querySelector("table");
      table.classList.add("admin-orders-table");
      table.querySelectorAll("tbody tr").forEach(function(row) {
        row.querySelectorAll("td").forEach(function(cell, index) {
          cell.setAttribute("data-label", headings[index]);
        });
      });
    }

    async function loadAdminOrders() {
      var orders = await api(searchUrl("/api/admin-orders", "admin-orders"));
      drawAdminOrders(document.getElementById("admin-orders-list"), orders);
      showSearchCount("admin-orders", orders.length);
      showMessage(document.getElementById("orders-message"), "", "");
    }

    function orderIsLocked() {
      return editingOrder && (editingOrder.status === "Completed" || editingOrder.status === "Cancelled" ||
        editingOrder.has_unlinked_items);
    }

    function updateEditorTotal() {
      var total = editingItems.reduce(function(sum, item) {
        item.subtotal = Number(item.price) * Number(item.quantity);
        return sum + item.subtotal;
      }, 0);
      document.getElementById("edit-order-total").textContent = "₱" + total.toFixed(2);
    }

    function drawEditorItems() {
      var box = document.getElementById("edit-order-items");
      var headings = ["Item", "Quantity", "Price", "Subtotal", "Action"];
      var rows = [];
      editingItems.forEach(function(item, index) {
        var row = document.createElement("tr");
        var name = document.createElement("td");
        name.textContent = item.item_name;
        var quantityCell = document.createElement("td");
        var quantity = document.createElement("input");
        quantity.type = "number";
        quantity.min = "1";
        quantity.max = "100";
        quantity.value = item.quantity;
        quantity.disabled = orderIsLocked() || document.getElementById("edit-order-status").value === "Cancelled";
        quantity.addEventListener("input", function() {
          editingItems[index].quantity = Number(quantity.value);
          subtotal.textContent = "₱" + (Number(item.price) * Number(quantity.value)).toFixed(2);
          updateEditorTotal();
        });
        quantityCell.appendChild(quantity);
        var price = document.createElement("td");
        price.textContent = "₱" + Number(item.price).toFixed(2);
        var subtotal = document.createElement("td");
        subtotal.textContent = "₱" + (Number(item.price) * Number(item.quantity)).toFixed(2);
        var action = document.createElement("td");
        var remove = document.createElement("button");
        remove.type = "button";
        remove.className = "remove-order-item";
        remove.textContent = "Remove";
        remove.disabled = orderIsLocked() || document.getElementById("edit-order-status").value === "Cancelled";
        remove.addEventListener("click", function() {
          if (editingItems.length <= 1) {
            showMessage(document.getElementById("order-editor-message"),
              "An order must have at least one item. Cancel the order to remove all items.", "error");
            return;
          }
          editingItems.splice(index, 1);
          drawEditorItems();
        });
        action.appendChild(remove);
        row.append(name, quantityCell, price, subtotal, action);
        rows.push(row);
      });
      drawTable(box, headings, rows);
      updateEditorTotal();
    }

    document.getElementById("edit-order-status").addEventListener("change", function() {
      var hideItems = orderIsLocked() || this.value === "Cancelled";
      document.getElementById("order-add-controls").classList.toggle("hidden", hideItems);
      var payment = document.getElementById("edit-order-payment");
      if (this.value === "Cancelled") payment.value = "Unpaid";
      payment.disabled = orderIsLocked() || this.value === "Cancelled";
      drawEditorItems();
    });

    async function openOrderEditor(orderNumber) {
      try {
        var result = await api("/api/admin-orders/" + encodeURIComponent(orderNumber));
        editingOrder = result.order;
        editingOrder.has_unlinked_items = result.items.length === 0 ||
          result.items.some(function(item) { return item.food_id == null; });
        editingItems = result.items.map(function(item) {
          return {
            food_id: item.food_id,
            item_name: item.item_name,
            quantity: Number(item.quantity),
            price: Number(item.price)
          };
        });
        editableFoods = await api("/api/inventory");
        document.getElementById("order-editor-title").textContent = orderIsLocked() ? "View Order" : "Edit Order";
        document.getElementById("order-editor-meta").textContent =
          "Order #" + editingOrder.order_number + " · " + editingOrder.student_name + " · " + displayDate(editingOrder.order_date);
        document.getElementById("edit-order-status").value = editingOrder.status;
        document.getElementById("edit-order-payment").value = editingOrder.payment_status;
        if (editingOrder.status === "Cancelled") {
          document.getElementById("edit-order-payment").value = "Unpaid";
        }
        document.getElementById("edit-order-note").value = editingOrder.note || "";
        document.getElementById("edit-order-items").textContent = "";
        showMessage(document.getElementById("order-editor-message"), "", "");
        var locked = orderIsLocked();
        document.getElementById("edit-order-status").disabled = locked;
        document.getElementById("edit-order-payment").disabled =
          editingOrder.status === "Cancelled" ||
          (orderIsLocked() && !(editingOrder.status === "Completed" && editingOrder.payment_status === "Unpaid"));
        document.getElementById("edit-order-note").disabled = locked;
        document.getElementById("order-add-controls").classList.toggle("hidden", locked);
        document.getElementById("order-editor-cancel").textContent = "Close";
        var save = document.getElementById("order-editor-save");
        save.classList.toggle("hidden", editingOrder.has_unlinked_items || editingOrder.status === "Cancelled" ||
          (editingOrder.status === "Completed" && editingOrder.payment_status === "Paid"));
        if (editingOrder.status === "Completed") save.textContent = "Mark Paid";
        else save.textContent = "Save Changes";
        var select = document.getElementById("add-order-food");
        select.textContent = "";
        editableFoods.forEach(function(food) {
          var option = document.createElement("option");
          option.value = food.id;
          option.textContent = food.name + " — ₱" + Number(food.price).toFixed(2) + " (" + food.stock + " in stock)";
          select.appendChild(option);
        });
        drawEditorItems();
        openModal(document.getElementById("order-editor"));
      } catch (error) {
        showMessage(document.getElementById("orders-message"), error.message, "error");
      }
    }

    document.getElementById("add-order-item").addEventListener("click", function() {
      var foodId = Number(document.getElementById("add-order-food").value);
      var quantity = Number(document.getElementById("add-order-quantity").value);
      var food = editableFoods.find(function(item) { return Number(item.id) === foodId; });
      if (!food || !Number.isInteger(quantity) || quantity < 1 || quantity > 100) return;
      var existing = editingItems.find(function(item) { return Number(item.food_id) === foodId; });
      if (existing) existing.quantity += quantity;
      else editingItems.push({ food_id: foodId, item_name: food.name, quantity: quantity, price: Number(food.price) });
      document.getElementById("add-order-quantity").value = "1";
      showMessage(document.getElementById("order-editor-message"), "", "");
      drawEditorItems();
    });

    document.getElementById("order-editor-save").addEventListener("click", async function() {
      if (!editingOrder) return;
      var status = document.getElementById("edit-order-status").value;
      var payment = document.getElementById("edit-order-payment").value;
      var items = editingItems.map(function(item) {
        return { food_id: item.food_id, quantity: Number(item.quantity) };
      });
      try {
        var saved = await api("/api/admin-orders/" + encodeURIComponent(editingOrder.order_number), {
          method: "PUT",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            status: status, payment_status: payment, note: document.getElementById("edit-order-note").value,
            items: items
          })
        });
        closeModal(document.getElementById("order-editor"));
        editingOrder = null;
        showMessage(document.getElementById("orders-message"), "", "");
        showToast(saved.message || "Order changes saved.", "success");
        await loadAdminOrders();
      } catch (error) {
        showMessage(document.getElementById("order-editor-message"), error.message, "error");
      }
    });

    document.getElementById("order-editor-cancel").addEventListener("click", function() {
      closeModal(document.getElementById("order-editor"));
      editingOrder = null;
    });
    document.getElementById("order-editor").addEventListener("click", function(event) {
      if (event.target === event.currentTarget) {
        closeModal(event.currentTarget);
        editingOrder = null;
      }
    });

    async function loadInventory() {
      var items = await api(searchUrl("/api/inventory", "inventory"));
      var rows = items.map(function(item) {
        var row = document.createElement("tr");
        [item.name, item.category, "₱" + Number(item.price).toFixed(2), item.stock, item.low_stock_level, item.status].forEach(function(value) {
          var cell = document.createElement("td");
          cell.textContent = value;
          row.appendChild(cell);
        });
        var actions = document.createElement("td");
        var edit = document.createElement("button");
        edit.type = "button";
        edit.className = "inventory-action";
        edit.textContent = "Edit";
        edit.addEventListener("click", function() {
          document.getElementById("inventory-id").value = item.id;
          document.getElementById("inventory-name").value = item.name;
          document.getElementById("inventory-price").value = Number(item.price).toFixed(2);
          document.getElementById("inventory-category").value = item.category;
          document.getElementById("inventory-stock").value = item.stock;
          document.getElementById("inventory-low-stock").value = item.low_stock_level;
          document.getElementById("inventory-save").textContent = "Save Changes";
          document.getElementById("inventory-cancel").classList.remove("hidden");
          document.getElementById("inventory-name").focus();
        });
        var remove = document.createElement("button");
        remove.type = "button";
        remove.className = "inventory-action delete";
        remove.textContent = "Delete";
        remove.addEventListener("click", async function() {
          if (!confirm("Delete " + item.name + " from inventory?")) return;
          try {
            await api("/api/inventory/" + encodeURIComponent(item.id), { method: "DELETE" });
            showMessage(document.getElementById("inventory-message"), "", "");
            showToast("Inventory item deleted.", "success");
            await loadInventory();
          } catch (error) {
            showMessage(document.getElementById("inventory-message"), error.message, "error");
          }
        });
        actions.append(edit, remove);
        row.appendChild(actions);
        return row;
      });
      drawTable(document.getElementById("inventory-list"),
        ["Item Name", "Category", "Price", "Stock", "Low Stock At", "Status", "Actions"], rows);
      showSearchCount("inventory", items.length);
      showMessage(document.getElementById("inventory-message"), "", "");
    }

    function resetInventoryForm() {
      var form = document.getElementById("inventory-form");
      form.reset();
      document.getElementById("inventory-id").value = "";
      document.getElementById("inventory-low-stock").value = "5";
      document.getElementById("inventory-save").textContent = "Add Item";
      document.getElementById("inventory-cancel").classList.add("hidden");
    }

    document.getElementById("inventory-cancel").addEventListener("click", resetInventoryForm);
    document.getElementById("inventory-form").addEventListener("submit", async function(event) {
      event.preventDefault();
      var itemId = document.getElementById("inventory-id").value;
      var item = {
        name: document.getElementById("inventory-name").value,
        price: document.getElementById("inventory-price").value,
        category: document.getElementById("inventory-category").value,
        stock: document.getElementById("inventory-stock").value,
        low_stock_level: document.getElementById("inventory-low-stock").value
      };
      try {
        var result = await api(itemId ? "/api/inventory/" + encodeURIComponent(itemId) : "/api/inventory", {
          method: itemId ? "PUT" : "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(item)
        });
        resetInventoryForm();
        showMessage(document.getElementById("inventory-message"), "", "");
        showToast(result.message, "success");
        await loadInventory();
      } catch (error) {
        showMessage(document.getElementById("inventory-message"), error.message, "error");
      }
    });

    async function loadSales() {
      var sales = await api(searchUrl("/api/sales", "sales"));
      drawTable(document.getElementById("sales-list"), ["Date", "Total Amount"], sales.map(function(row) {
        return [row.sale_date, Number(row.total_amount).toFixed(2)];
      }));
      showSearchCount("sales", sales.length);
      showMessage(document.getElementById("sales-message"), "", "");
    }

    function showAdminSearchError(error, section) {
      var messageIds = {
        inventory: "inventory-message",
        orders: "orders-message",
        sales: "sales-message"
      };
      showMessage(document.getElementById(messageIds[section] || "users-message"), error.message, "error");
    }

    var route = location.pathname;
    var view = route === "/signup" ? "signup" : route === "/student" ? "student" : route === "/admin" ? "admin" : "login";
    document.querySelector('[data-view="' + view + '"]').classList.remove("hidden");

    if (view === "login") {
      var loginMessage = document.getElementById("login-message");
      var signupSuccess = "";
      try { signupSuccess = sessionStorage.getItem("signup-success") || ""; }
      catch (error) { console.warn("Session storage is unavailable."); }
      if (signupSuccess) {
        showMessage(loginMessage, "Account created. Your ID is " + signupSuccess + ". Remember it for future logins.", "success");
        try { sessionStorage.removeItem("signup-success"); }
        catch (error) { console.warn("Could not clear the signup message."); }
      } else if (new URLSearchParams(location.search).has("error")) {
        showMessage(loginMessage, "Wala kang pahintulot na buksan ang panel na iyon.", "error");
      }
    }

    if (view === "signup") {
      var role = document.getElementById("role");
      var terms = document.getElementById("terms");
      var createButton = document.getElementById("create-account");
      var termsPopup = document.getElementById("terms-popup");
      var adminWrap = document.getElementById("admin-code-wrap");
      var adminCode = document.getElementById("admin-code");
      document.getElementById("terms-link").addEventListener("click", function(event) {
        event.preventDefault();
        openModal(termsPopup);
      });
      document.getElementById("terms-popup-close").addEventListener("click", function() {
        closeModal(termsPopup);
      });
      termsPopup.addEventListener("click", function(event) {
        if (event.target === termsPopup) closeModal(termsPopup);
      });
      function updateSignupFields() {
        var isAdmin = role.value === "admin";
        adminWrap.classList.toggle("hidden", !isAdmin);
        adminCode.required = isAdmin;
        createButton.disabled = !terms.checked;
      }
      role.addEventListener("change", updateSignupFields);
      terms.addEventListener("change", updateSignupFields);
      updateSignupFields();
      document.getElementById("signup-form").addEventListener("submit", async function(event) {
        event.preventDefault();
        var form = event.currentTarget;
        var values = Object.fromEntries(new FormData(form).entries());
        values.terms = terms.checked;
        try {
          var result = await api("/api/signup", {
            method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(values)
          });
          try { sessionStorage.setItem("signup-success", result.user_code); }
          catch (error) { console.warn("Session storage is unavailable."); }
          location.href = "/";
        } catch (error) {
          showMessage(document.getElementById("signup-message"), error.message, "error");
        }
      });
    }

    if (view === "login") {
      document.getElementById("login-form").addEventListener("submit", async function(event) {
        event.preventDefault();
        var values = Object.fromEntries(new FormData(event.currentTarget).entries());
        try {
          var result = await api("/api/login", {
            method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(values)
          });
          location.href = result.redirect;
        } catch (error) {
          showMessage(document.getElementById("login-message"), error.message, "error");
        }
      });
    }

    if (view === "student" || view === "admin") {
      document.body.classList.add("dashboard-page");
      if (view === "admin") {
        installSearchBar("users-search", "users", [
          ["user_code", "User ID"], ["full_name", "Full Name"], ["email", "Email"],
          ["role", "Role"], ["date_created", "Date Created"]
        ], true, function() { loadUsers(); });
        installSearchBar("admin-orders-search", "admin-orders", [
          ["order_number", "Order Number"], ["student_id", "Student ID"], ["student_name", "Student Name"],
          ["item_name", "Item Name"], ["total_amount", "Total Amount"], ["date", "Date"], ["status", "Status"]
        ], true, function() { loadAdminOrders().catch(function(error) { showAdminSearchError(error, "orders"); }); });
        installSearchBar("inventory-search", "inventory", [
          ["item_name", "Item Name"], ["price", "Price"], ["category", "Category"], ["stock", "Stock"], ["status", "Status"]
        ], false, function() { loadInventory().catch(function(error) { showAdminSearchError(error, "inventory"); }); });
        installSearchBar("sales-search", "sales", [
          ["date", "Date"], ["total_amount", "Total Amount"]
        ], true, function() { loadSales().catch(function(error) { showAdminSearchError(error, "sales"); }); });
        document.getElementById("export-sales").addEventListener("click", function() {
          downloadCsv(searchUrl("/api/admin/export/sales", "sales")).catch(function(error) {
            showToast(error.message, "error");
          });
        });
        document.getElementById("export-orders").addEventListener("click", function() {
          downloadCsv(searchUrl("/api/admin/export/orders", "admin-orders")).catch(function(error) {
            showToast(error.message, "error");
          });
        });
        document.getElementById("user-filter").addEventListener("change", function() {
          if (activeSection === "accounts") loadUsers();
        });
      } else {
        installSearchBar("menu-search", "menu", [
          ["item_name", "Item Name"], ["price", "Price"]
        ], false, function() { loadMenu(); });
        installSearchBar("student-orders-search", "student-orders", [
          ["order_number", "Order Number"], ["date", "Date"], ["item_name", "Item Name"],
          ["total_amount", "Total Amount"]
        ], true, function() { loadOrders(); });
      }
      document.querySelectorAll('[data-view="' + view + '"] [data-section-link]').forEach(function(button) {
        button.addEventListener("click", function() {
          showSection(button.getAttribute("data-section-link"), true);
          document.body.classList.remove("sidebar-open");
        });
      });
      document.querySelector('[data-view="' + view + '"] .mobile-menu-button').addEventListener("click", function() {
        document.body.classList.toggle("sidebar-open");
      });
      document.querySelector('[data-view="' + view + '"] .mobile-sidebar-shade').addEventListener("click", function() {
        document.body.classList.remove("sidebar-open");
      });
      if (view === "admin") {
        document.querySelectorAll("[data-status-filter]").forEach(function(button) {
          button.addEventListener("click", function() {
            var row = document.querySelector("#admin-orders-search .search-tools");
            row.querySelector("select").value = "status";
            row.querySelector('input[type="search"]').value = button.getAttribute("data-status-filter");
            row.querySelectorAll('input[type="date"]').forEach(function(input) { input.value = ""; });
            showSection("orders", true);
          });
        });
      }
      window.addEventListener("hashchange", function() {
        showSection(location.hash.slice(1), false);
      });

      api("/api/me").then(function(user) {
        currentUser = user;
        if (view === "student") document.getElementById("student-welcome").textContent = "Welcome, " + user.full_name + "!";
        if (view === "admin") {
          document.getElementById("admin-welcome").textContent = "Welcome back, " + user.full_name;
        }
        document.getElementById(view + "-nav-name").textContent = user.full_name;
        document.getElementById(view + "-nav-id").textContent = user.user_code;
        if (activeSection === "profile") {
          showProfile(document.getElementById(view + "-profile"), user);
        }
      }).catch(function(error) {
        console.error("Could not load the signed-in account:", error);
        showToast("Could not load account details. " + error.message, "error");
      });

      if (view === "student") {
        document.getElementById("confirm-cart-order").addEventListener("click", async function() {
          if (!studentCart.length) return;
          var button = document.getElementById("confirm-cart-order");
          button.disabled = true;
          showMessage(document.getElementById("order-message"), "Placing your order...", "loading");
          try {
            var result = await api("/api/student-orders", {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({
                items: studentCart.map(function(item) {
                  return { food_id: item.food_id, quantity: item.quantity };
                })
              })
            });
            studentCart = [];
            drawStudentCart();
            showMessage(document.getElementById("order-message"), "", "");
            showToast("Order confirmed. Total to pay: ₱" + Number(result.total_amount).toFixed(2), "success");
            await loadMenu();
          } catch (error) {
            showMessage(document.getElementById("order-message"), error.message, "error");
            button.disabled = studentCart.length === 0;
          }
        });
        drawStudentCart();
      }
      var startSection = location.hash.slice(1) || "dashboard";
      showSection(startSection, !location.hash);
      document.querySelectorAll(".logout").forEach(function(button) {
        button.addEventListener("click", async function() {
          try { await api("/api/logout", { method: "POST" }); } finally { location.href = "/"; }
        });
      });
    }
  </script>
</body>
</html>`;

function sendJson(response, status, data, headers) {
  var body = JSON.stringify(data);
  response.writeHead(status, Object.assign({ "Content-Type": "application/json; charset=utf-8" }, headers || {}));
  response.end(body);
}

function toCsv(rows) {
  return rows.map(function(row) {
    return row.map(function(value) {
      var text = value == null ? "" : String(value);
      if (/^[=+\-@]/.test(text)) text = "'" + text;
      return '"' + text.replace(/"/g, '""') + '"';
    }).join(",");
  }).join("\r\n");
}

function fileDate() {
  var now = new Date();
  return now.getFullYear() + "-" + String(now.getMonth() + 1).padStart(2, "0") +
    "-" + String(now.getDate()).padStart(2, "0");
}

function sendCsv(response, filenamePrefix, rows) {
  response.writeHead(200, {
    "Content-Type": "text/csv; charset=utf-8",
    "Content-Disposition": "attachment; filename=\"kantease-" + filenamePrefix + "-" + fileDate() + ".csv\""
  });
  response.end("\uFEFF" + toCsv(rows));
}

function inventoryItemFrom(body) {
  if (!body || typeof body !== "object" || Array.isArray(body)) {
    fail("Enter valid item details.", 400);
  }
  var name = typeof body.name === "string" ? body.name.trim() : "";
  var price = Number(body.price);
  var stock = Number(body.stock);
  var lowStock = Number(body.low_stock_level);
  var category = body.category;
  if (!name || name.length > 120 || body.price == null || String(body.price).trim() === "" ||
      body.stock == null || String(body.stock).trim() === "" ||
      body.low_stock_level == null || String(body.low_stock_level).trim() === "" ||
      !Number.isFinite(price) || price <= 0 || price > 99999999.99 ||
      FOOD_CATEGORIES.indexOf(category) === -1 ||
      !Number.isSafeInteger(stock) || stock < 0 || stock > 2147483647 ||
      !Number.isSafeInteger(lowStock) || lowStock < 0 || lowStock > 2147483647) {
    fail("Enter a name, valid category, positive price, stock, and low-stock level.", 400);
  }
  return {
    name: name,
    price: price.toFixed(2),
    stock: stock,
    low_stock_level: lowStock,
    category: category
  };
}

function fail(message, status) {
  var error = new Error(message);
  error.status = status;
  throw error;
}

function readBody(request) {
  return new Promise(function(resolve, reject) {
    var body = "";
    request.on("data", function(chunk) {
      body += chunk;
      if (body.length > 100000) {
        var error = new Error("Request body is too large.");
        error.status = 413;
        reject(error);
        request.destroy();
      }
    });
    request.on("end", function() {
      try { resolve(JSON.parse(body || "{}")); }
      catch (error) { error.status = 400; reject(error); }
    });
    request.on("error", reject);
  });
}

function hashPassword(password, salt) {
  return new Promise(function(resolve, reject) {
    crypto.scrypt(password, salt, 64, function(error, key) {
      if (error) reject(error);
      else resolve(key);
    });
  });
}

async function makePassword(password) {
  var salt = crypto.randomBytes(16).toString("hex");
  var hash = await hashPassword(password, salt);
  return salt + ":" + hash.toString("hex");
}

async function checkPassword(password, stored) {
  var parts = stored.split(":");
  if (parts.length !== 2 || !/^[a-f0-9]{32}$/.test(parts[0]) || !/^[a-f0-9]{128}$/.test(parts[1])) return false;
  var actual = await hashPassword(password, parts[0]);
  return crypto.timingSafeEqual(actual, Buffer.from(parts[1], "hex"));
}

function sessionFor(request) {
  var cookies = (request.headers.cookie || "").split(";");
  var entry = cookies.find(function(cookie) { return cookie.trim().startsWith("session="); });
  if (!entry) return null;
  var token = entry.trim().slice("session=".length);
  var session = sessions.get(token);
  if (!session || session.expiresAt < Date.now()) {
    sessions.delete(token);
    return null;
  }
  return { token: token, session: session };
}

function requireRole(request, response, role) {
  var current = sessionFor(request);
  if (!current) {
    sendJson(response, 401, { error: "Mag-log in muna." });
    return null;
  }
  if (role && current.session.role !== role) {
    sendJson(response, 403, { error: "Wala kang pahintulot para rito." });
    return null;
  }
  return current;
}

// Search fields are fixed here so request values never become SQL columns.
var allowedSearchFields = {
  studentOrders: {
    order_number: "o.id",
    date: { column: "o.order_date", isDate: true },
    item_name: "(SELECT GROUP_CONCAT(oi.item_name SEPARATOR ', ') FROM order_items oi WHERE oi.order_id = o.id)",
    total_amount: "o.total_amount",
    all: ["order_number", "date", "item_name", "total_amount"],
    range: "o.order_date"
  },
  menu: {
    item_name: "f.name",
    price: "f.price",
    all: ["item_name", "price"]
  },
  users: {
    user_code: "u.user_code",
    full_name: "u.full_name",
    email: "u.email",
    role: "u.role",
    date_created: { column: "u.created_at", isDate: true },
    all: ["user_code", "full_name", "email", "role", "date_created"],
    range: "u.created_at"
  },
  adminOrders: {
    order_number: "o.id",
    student_id: "u.user_code",
    student_name: "u.full_name",
    item_name: "(SELECT GROUP_CONCAT(oi.item_name SEPARATOR ', ') FROM order_items oi WHERE oi.order_id = o.id)",
    total_amount: "o.total_amount",
    date: { column: "o.order_date", isDate: true },
    status: "o.status",
    all: ["order_number", "student_id", "student_name", "item_name", "total_amount", "date", "status"],
    range: "o.order_date"
  },
  inventory: {
    item_name: "f.name",
    price: "f.price",
    category: "f.category",
    stock: "f.stock",
    status: "CASE WHEN f.stock <= f.low_stock_level THEN 'Low' ELSE 'OK' END",
    all: ["item_name", "price", "category", "stock", "status"]
  },
  sales: {
    date: { column: "s.sale_date", isDate: true },
    total_amount: "s.total_amount",
    all: ["date", "total_amount"],
    range: "s.sale_date"
  }
};

function validDate(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  var date = new Date(value + "T00:00:00Z");
  return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === value;
}

function buildSearch(section, url) {
  var fields = allowedSearchFields[section];
  var field = url.searchParams.get("field") || "all";
  if (!Object.prototype.hasOwnProperty.call(fields, field) || field === "range") field = "all";
  var text = url.searchParams.get("q") || "";
  if (text.length > 100) fail("Search text is too long.", 400);
  var clauses = [];
  var params = [];

  if (text) {
    var selected = field === "all" ? fields.all : [field];
    var matches = selected.map(function(name) {
      var definition = fields[name];
      var column = typeof definition === "string" ? definition : definition.column;
      if (typeof definition === "object" && definition.isDate) {
        return "DATE_FORMAT(" + column + ", '%Y-%m-%d %H:%i:%s') LIKE ?";
      }
      return "CAST((" + column + ") AS CHAR) LIKE ?";
    });
    clauses.push("(" + matches.join(" OR ") + ")");
    selected.forEach(function() { params.push("%" + text + "%"); });
  }

  if (fields.range) {
    var from = url.searchParams.get("from") || "";
    var to = url.searchParams.get("to") || "";
    if ((from && !validDate(from)) || (to && !validDate(to))) fail("Enter valid From and To dates.", 400);
    if (from || to) {
      if (from && to && from > to) fail("From date must not be after To date.", 400);
      clauses.push(fields.range + " BETWEEN ? AND ?");
      params.push(from ? from + " 00:00:00" : "1000-01-01 00:00:00");
      params.push(to ? to + " 23:59:59" : "9999-12-31 23:59:59");
    }
  }
  return { sql: clauses.length ? " AND " + clauses.join(" AND ") : "", params: params };
}

async function handleRequest(request, response) {
  var url = new URL(request.url, "http://localhost");
  try {
    if (request.method === "GET" && ["/", "/signup", "/student", "/admin"].includes(url.pathname)) {
      if (url.pathname === "/student" || url.pathname === "/admin") {
        var current = sessionFor(request);
        var required = url.pathname === "/admin" ? "admin" : "student";
        if (!current) {
          response.writeHead(302, { Location: "/" });
          response.end();
          return;
        }
        if (current.session.role !== required) {
          response.writeHead(302, { Location: current.session.role === "admin" ? "/admin" : "/student?error=forbidden" });
          response.end();
          return;
        }
      }
      response.writeHead(200, { "Content-Type": "text/html; charset=utf-8", "Cache-Control": "no-store" });
      response.end(page);
      return;
    }

    if (request.method === "POST" && url.pathname === "/api/signup") {
      var signup = await readBody(request);
      var name = typeof signup.full_name === "string" ? signup.full_name.trim() : "";
      var email = typeof signup.email === "string" ? signup.email.trim().toLowerCase() : "";
      var password = typeof signup.password === "string" ? signup.password : "";
      var role = signup.role;
      if (!name || name.length > 120 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || email.length > 254) fail("Maglagay ng valid na pangalan at email.", 400);
      if (password.length < 8 || password.length > 128 || password !== signup.confirm_password) fail("Dapat magkapareho ang password at kumpirmasyon nito, at may 8 hanggang 128 character.", 400);
      if (signup.terms !== true) fail("Kailangan mong sumang-ayon sa Terms and Conditions.", 400);
      if (role !== "student" && role !== "admin") fail("Pumili ng valid na role.", 400);
      if (role === "admin" && signup.admin_code !== ADMIN_CODE) fail("Mali ang Admin Code.", 400);

      var passwordHash = await makePassword(password);
      var connection = await pool.getConnection();
      var userCode;
      try {
        await connection.beginTransaction();
        var sequence = await connection.query("SELECT next_number FROM id_sequences WHERE role = ? FOR UPDATE", [role]);
        var nextNumber = Number(sequence[0].next_number);
        var prefix = role === "student" ? "STU-" : "ADM-";
        userCode = prefix + String(nextNumber).padStart(4, "0");
        await connection.query("UPDATE id_sequences SET next_number = ? WHERE role = ?", [nextNumber + 1, role]);
        await connection.query(
          "INSERT INTO users (user_code, full_name, email, password, role) VALUES (?, ?, ?, ?, ?)",
          [userCode, name, email, passwordHash, role]
        );
        await connection.commit();
      } catch (error) {
        await connection.rollback();
        throw error;
      } finally { connection.release(); }
      sendJson(response, 201, { message: "Account created.", user_code: userCode });
      return;
    }

    if (request.method === "POST" && url.pathname === "/api/login") {
      var login = await readBody(request);
      var userId = typeof login.user_id === "string" ? login.user_id.trim() : "";
      var loginPassword = typeof login.password === "string" ? login.password : "";
      var found = await pool.query(
        "SELECT id, user_code, full_name, email, password, role FROM users WHERE user_code = ? OR email = ? LIMIT 1",
        [userId.toUpperCase(), userId.toLowerCase()]
      );
      if (!found.length || !(await checkPassword(loginPassword, found[0].password))) fail("Mali ang User ID/email o password.", 401);
      var token = crypto.randomBytes(32).toString("hex");
      sessions.set(token, { id: found[0].id, role: found[0].role, expiresAt: Date.now() + SESSION_MS });
      sendJson(response, 200, { redirect: found[0].role === "admin" ? "/admin" : "/student" }, {
        "Set-Cookie": "session=" + token + "; HttpOnly; SameSite=Strict; Path=/; Max-Age=28800"
      });
      return;
    }

    if (request.method === "POST" && url.pathname === "/api/logout") {
      var logout = sessionFor(request);
      if (logout) sessions.delete(logout.token);
      sendJson(response, 200, { message: "Logged out." }, { "Set-Cookie": "session=; HttpOnly; SameSite=Strict; Path=/; Max-Age=0" });
      return;
    }

    if (request.method === "POST" && url.pathname === "/api/change-password") {
      var passwordSession = requireRole(request, response);
      if (!passwordSession) return;
      var passwordChange = await readBody(request);
      if (!passwordChange || typeof passwordChange !== "object" || Array.isArray(passwordChange)) {
        fail("Enter valid password details.", 400);
      }
      var currentPassword = typeof passwordChange.current_password === "string" ? passwordChange.current_password : "";
      var newPassword = typeof passwordChange.new_password === "string" ? passwordChange.new_password : "";
      var confirmPassword = typeof passwordChange.confirm_password === "string" ? passwordChange.confirm_password : "";
      if (!currentPassword) fail("Enter your current password.", 400);
      if (newPassword.length < 8 || newPassword.length > 128) fail("New password must be between 8 and 128 characters.", 400);
      if (newPassword !== confirmPassword) fail("New password and confirmation do not match.", 400);
      if (newPassword === currentPassword) fail("Choose a password different from your current one.", 400);
      var savedPassword = await pool.query("SELECT password FROM users WHERE id = ?", [passwordSession.session.id]);
      if (!savedPassword.length || !(await checkPassword(currentPassword, savedPassword[0].password))) {
        fail("Current password is incorrect.", 400);
      }
      var changedPasswordHash = await makePassword(newPassword);
      await pool.query("UPDATE users SET password = ? WHERE id = ?", [changedPasswordHash, passwordSession.session.id]);
      for (var activeSession of sessions.entries()) {
        if (Number(activeSession[1].id) === Number(passwordSession.session.id) &&
            activeSession[0] !== passwordSession.token) sessions.delete(activeSession[0]);
      }
      sendJson(response, 200, { message: "Password changed successfully." });
      return;
    }

    if (request.method === "PUT" && url.pathname === "/api/me") {
      var profileUpdateSession = requireRole(request, response);
      if (!profileUpdateSession) return;
      var profileChanges = await readBody(request);
      if (!profileChanges || typeof profileChanges !== "object" || Array.isArray(profileChanges)) {
        fail("Enter valid profile details.", 400);
      }
      var profileName = typeof profileChanges.full_name === "string" ? profileChanges.full_name.trim() : "";
      var profileEmail = typeof profileChanges.email === "string" ? profileChanges.email.trim().toLowerCase() : "";
      if (!profileName || profileName.length > 120) fail("Full name is required and must be 120 characters or fewer.", 400);
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(profileEmail) || profileEmail.length > 254) {
        fail("Enter a valid email address.", 400);
      }
      var existingProfileEmail = await pool.query(
        "SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1",
        [profileEmail, profileUpdateSession.session.id]
      );
      if (existingProfileEmail.length) fail("Email is already used by another account.", 409);
      try {
        await pool.query(
          "UPDATE users SET full_name = ?, email = ? WHERE id = ?",
          [profileName, profileEmail, profileUpdateSession.session.id]
        );
      } catch (error) {
        if (error.code === "ER_DUP_ENTRY") fail("Email is already used by another account.", 409);
        throw error;
      }
      var updatedProfile = await pool.query(
        "SELECT id, user_code, full_name, email, role, created_at FROM users WHERE id = ?",
        [profileUpdateSession.session.id]
      );
      if (!updatedProfile.length) fail("Hindi na makita ang account. Mag-log in ulit.", 401);
      sendJson(response, 200, updatedProfile[0]);
      return;
    }

    if (request.method === "GET" && url.pathname === "/api/me") {
      var me = requireRole(request, response);
      if (!me) return;
      var profile = await pool.query("SELECT id, user_code, full_name, email, role, created_at FROM users WHERE id = ?", [me.session.id]);
      if (!profile.length) fail("Hindi na makita ang account. Mag-log in ulit.", 401);
      sendJson(response, 200, profile[0]);
      return;
    }

    if (request.method === "GET" && url.pathname === "/api/menu") {
      if (!requireRole(request, response, "student")) return;
      var menuSearch = buildSearch("menu", url);
      var menuItems = await pool.query(
        "SELECT f.id, f.name, f.price, f.stock, f.low_stock_level, f.category FROM food_items f WHERE 1 = 1" +
        menuSearch.sql + " ORDER BY f.name",
        menuSearch.params
      );
      sendJson(response, 200, menuItems);
      return;
    }

    if (request.method === "GET" && url.pathname === "/api/student-orders") {
      var student = requireRole(request, response, "student");
      if (!student) return;
      var studentSearch = buildSearch("studentOrders", url);
      var orders = await pool.query(
        "SELECT o.id AS order_number, " +
        "GROUP_CONCAT(CONCAT(oi.item_name, ' x', oi.quantity) ORDER BY oi.id SEPARATOR ', ') AS item_name, " +
        "o.total_amount, o.order_date, o.status, o.payment_status " +
        "FROM orders o LEFT JOIN order_items oi ON oi.order_id = o.id " +
        "WHERE o.user_id = ?" + studentSearch.sql +
        " GROUP BY o.id, o.total_amount, o.order_date, o.status, o.payment_status " +
        "ORDER BY o.order_date DESC, o.id DESC",
        [student.session.id].concat(studentSearch.params)
      );
      sendJson(response, 200, orders);
      return;
    }

    if (request.method === "POST" && url.pathname === "/api/student-orders") {
      var student = requireRole(request, response, "student");
      if (!student) return;
      var order = await readBody(request);
      if (!order || typeof order !== "object" || Array.isArray(order)) fail("Choose menu items and quantities.", 400);
      var requestedItems = Array.isArray(order.items) ? order.items : [{
        food_id: order.food_id, quantity: order.quantity
      }];
      if (requestedItems.length < 1 || requestedItems.length > 20) {
        fail("An order must contain from 1 to 20 different items.", 400);
      }
      var quantities = {};
      requestedItems.forEach(function(item) {
        var foodId = Number(item && item.food_id);
        var quantity = Number(item && item.quantity);
        if (!Number.isSafeInteger(foodId) || foodId < 1 ||
            !Number.isInteger(quantity) || quantity < 1 || quantity > 100) {
          fail("Each item needs a valid food and quantity from 1 to 100.", 400);
        }
        if (Object.prototype.hasOwnProperty.call(quantities, foodId)) {
          fail("A food item can only appear once in the cart.", 400);
        }
        quantities[foodId] = quantity;
      });
      var foodIds = Object.keys(quantities).map(Number).sort(function(a, b) { return a - b; });
      var orderConnection = await pool.getConnection();
      var orderTransactionStarted = false;
      try {
        await orderConnection.beginTransaction();
        orderTransactionStarted = true;
        var marks = foodIds.map(function() { return "?"; }).join(",");
        var foods = await orderConnection.query(
          "SELECT id, name, price, stock FROM food_items WHERE id IN (" + marks + ") ORDER BY id FOR UPDATE",
          foodIds
        );
        if (foods.length !== foodIds.length) fail("A selected menu item was not found.", 404);
        var foodById = {};
        foods.forEach(function(food) { foodById[String(food.id)] = food; });
        var totalCents = 0;
        var orderLines = foodIds.map(function(id) {
          var food = foodById[String(id)];
          var quantity = quantities[String(id)];
          if (Number(food.stock) < quantity) {
            fail("Not enough stock for " + food.name + ". Available stock: " + food.stock + ".", 400);
          }
          var priceCents = Math.round(Number(food.price) * 100);
          var subtotalCents = priceCents * quantity;
          totalCents += subtotalCents;
          return {
            food_id: id, name: food.name, quantity: quantity, price: Number(food.price),
            subtotal: (subtotalCents / 100).toFixed(2)
          };
        });
        if (!Number.isSafeInteger(totalCents) || totalCents > 9999999999) {
          fail("The order total is too large.", 400);
        }
        var amount = (totalCents / 100).toFixed(2);
        var newOrder = await orderConnection.query(
          "INSERT INTO orders (user_id, total_amount) VALUES (?, ?)",
          [student.session.id, amount]
        );
        for (var line of orderLines) {
          await orderConnection.query(
            "INSERT INTO order_items (order_id, food_id, item_name, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?, ?)",
            [newOrder.insertId, line.food_id, line.name, line.quantity, line.price.toFixed(2), line.subtotal]
          );
          var stockUpdate = await orderConnection.query(
            "UPDATE food_items SET stock = stock - ? WHERE id = ? AND stock >= ?",
            [line.quantity, line.food_id, line.quantity]
          );
          if (!stockUpdate.affectedRows) fail("Stock changed while placing the order. Please try again.", 409);
        }
        await orderConnection.commit();
        orderTransactionStarted = false;
      } catch (error) {
        if (orderTransactionStarted) await orderConnection.rollback();
        throw error;
      } finally { orderConnection.release(); }
      sendJson(response, 201, { message: "Order added.", total_amount: amount });
      return;
    }

    if (request.method === "GET" && url.pathname === "/api/admin/dashboard") {
      if (!requireRole(request, response, "admin")) return;
      var dashboardResults = await Promise.all([
        pool.query("SELECT COUNT(*) AS total FROM users WHERE role = ?", ["student"]),
        pool.query("SELECT COUNT(*) AS total FROM users WHERE role = ?", ["admin"]),
        pool.query(
          "SELECT COUNT(*) AS total FROM orders WHERE order_date >= CURDATE() " +
          "AND order_date < CURDATE() + INTERVAL 1 DAY"
        ),
        pool.query(
          "SELECT COALESCE(SUM(total_amount), 0) AS total FROM orders " +
          "WHERE order_date >= CURDATE() AND order_date < CURDATE() + INTERVAL 1 DAY AND status <> ?",
          ["Cancelled"]
        ),
        pool.query(
          "SELECT COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS pending, " +
          "COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS preparing, " +
          "COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS ready, " +
          "COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS completed " +
          "FROM orders",
          ["Pending", "Preparing", "Ready", "Completed"]
        ),
        pool.query(
          "SELECT DATE(order_date) AS sale_date, COALESCE(SUM(total_amount), 0) AS total_amount " +
          "FROM orders WHERE order_date >= CURDATE() - INTERVAL 6 DAY " +
          "AND order_date < CURDATE() + INTERVAL 1 DAY AND status <> ? " +
          "GROUP BY DATE(order_date) ORDER BY sale_date",
          ["Cancelled"]
        ),
        pool.query(
          "SELECT id, name, stock, low_stock_level FROM food_items " +
          "WHERE stock <= low_stock_level ORDER BY stock, name"
        ),
        pool.query(
          "SELECT oi.item_name, SUM(oi.quantity) AS quantity_sold " +
          "FROM order_items oi JOIN orders o ON o.id = oi.order_id " +
          "WHERE o.order_date >= CURDATE() - INTERVAL 6 DAY " +
          "AND o.order_date < CURDATE() + INTERVAL 1 DAY AND o.status <> ? " +
          "GROUP BY oi.food_id, oi.item_name ORDER BY quantity_sold DESC, oi.item_name LIMIT 5",
          ["Cancelled"]
        ),
        pool.query(
          "SELECT o.id AS order_number, u.full_name AS student_name, o.total_amount, " +
          "o.status, o.order_date FROM orders o JOIN users u ON u.id = o.user_id " +
          "ORDER BY o.order_date DESC, o.id DESC LIMIT 5"
        )
      ]);
      var status = dashboardResults[4][0] || {};
      sendJson(response, 200, {
        total_students: Number(dashboardResults[0][0].total) || 0,
        total_admins: Number(dashboardResults[1][0].total) || 0,
        today_orders: Number(dashboardResults[2][0].total) || 0,
        today_sales: Number(dashboardResults[3][0].total) || 0,
        status_counts: {
          Pending: Number(status.pending) || 0,
          Preparing: Number(status.preparing) || 0,
          Ready: Number(status.ready) || 0,
          Completed: Number(status.completed) || 0
        },
        sales_week: dashboardResults[5],
        low_stock: dashboardResults[6],
        best_sellers: dashboardResults[7],
        recent_orders: dashboardResults[8]
      });
      return;
    }

    if (request.method === "GET" && url.pathname === "/api/admin-orders") {
      if (!requireRole(request, response, "admin")) return;
      var orderSearch = buildSearch("adminOrders", url);
      var allOrders = await pool.query(
        "SELECT o.id AS order_number, u.user_code AS student_id, u.full_name AS student_name, " +
        "GROUP_CONCAT(CONCAT(oi.item_name, ' x', oi.quantity) ORDER BY oi.id SEPARATOR ', ') AS item_summary, " +
        "o.total_amount, o.status, o.payment_status, o.order_date, " +
        "MAX(CASE WHEN oi.id IS NULL OR oi.food_id IS NULL THEN 1 ELSE 0 END) AS has_unlinked_items " +
        "FROM orders o JOIN users u ON u.id = o.user_id " +
        "LEFT JOIN order_items oi ON oi.order_id = o.id WHERE 1 = 1" + orderSearch.sql +
        " GROUP BY o.id, u.user_code, u.full_name, o.total_amount, o.status, o.payment_status, o.order_date " +
        "ORDER BY o.order_date DESC, o.id DESC",
        orderSearch.params
      );
      sendJson(response, 200, allOrders);
      return;
    }

    var adminOrderMatch = url.pathname.match(/^\/api\/admin-orders\/(\d+)$/);
    if ((request.method === "GET" || request.method === "PUT") && adminOrderMatch) {
      if (!requireRole(request, response, "admin")) return;
      var targetOrderId = Number(adminOrderMatch[1]);
      if (!Number.isSafeInteger(targetOrderId) || targetOrderId < 1) fail("Invalid order number.", 400);

      if (request.method === "GET") {
        var orderRows = await pool.query(
          "SELECT o.id AS order_number, u.full_name AS student_name, o.order_date, o.status, " +
          "o.payment_status, o.note, o.total_amount FROM orders o " +
          "JOIN users u ON u.id = o.user_id WHERE o.id = ?",
          [targetOrderId]
        );
        if (!orderRows.length) fail("Order not found.", 404);
        var orderLines = await pool.query(
          "SELECT oi.food_id, oi.item_name, oi.quantity, oi.unit_price AS price, oi.subtotal " +
          "FROM order_items oi " +
          "WHERE oi.order_id = ? ORDER BY oi.id",
          [targetOrderId]
        );
        sendJson(response, 200, { order: orderRows[0], items: orderLines });
        return;
      }

      var changes = await readBody(request);
      if (!changes || typeof changes !== "object" || Array.isArray(changes)) fail("Invalid order changes.", 400);
      var allowedStatuses = ["Pending", "Preparing", "Ready", "Completed", "Cancelled"];
      var allowedPayments = ["Unpaid", "Paid"];
      if (!allowedStatuses.includes(changes.status) || !allowedPayments.includes(changes.payment_status)) {
        fail("Choose a valid order status and payment status.", 400);
      }
      if (changes.note != null && typeof changes.note !== "string") fail("Enter a valid note.", 400);
      var note = changes.note == null ? "" : changes.note.trim();
      if (note.length > 200) fail("The note can be up to 200 characters.", 400);

      var editConnection = await pool.getConnection();
      var editTransactionStarted = false;
      try {
        await editConnection.beginTransaction();
        editTransactionStarted = true;
        var lockedOrders = await editConnection.query(
          "SELECT id, status, payment_status, note FROM orders WHERE id = ? FOR UPDATE",
          [targetOrderId]
        );
        if (!lockedOrders.length) fail("Order not found.", 404);
        var oldOrder = lockedOrders[0];

        if (oldOrder.status === "Completed") {
          if (oldOrder.payment_status === "Unpaid" && changes.payment_status === "Paid" &&
              changes.status === "Completed" && note === (oldOrder.note || "")) {
            await editConnection.query(
              "UPDATE orders SET payment_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
              ["Paid", targetOrderId]
            );
            await editConnection.commit();
            editTransactionStarted = false;
            sendJson(response, 200, { message: "Payment updated." });
            return;
          }
          fail("Completed orders are locked. Only unpaid orders can be marked paid.", 409);
        }
        if (oldOrder.status === "Cancelled") fail("Cancelled orders are locked.", 409);

        var oldLines = await editConnection.query(
          "SELECT food_id, item_name, quantity, unit_price FROM order_items WHERE order_id = ? FOR UPDATE",
          [targetOrderId]
        );
        if (oldLines.some(function(line) { return line.food_id == null; })) {
          fail("This order has an item that is no longer linked to inventory, so its stock cannot be changed safely.", 409);
        }

        if (changes.status === "Cancelled") {
          var returnAmounts = {};
          oldLines.forEach(function(line) {
            var key = String(line.food_id);
            returnAmounts[key] = (returnAmounts[key] || 0) + Number(line.quantity);
          });
          var returnIds = Object.keys(returnAmounts).map(Number).sort(function(a, b) { return a - b; });
          if (returnIds.length) {
            var returnMarks = returnIds.map(function() { return "?"; }).join(",");
            var stockRows = await editConnection.query(
              "SELECT id FROM food_items WHERE id IN (" + returnMarks + ") ORDER BY id FOR UPDATE",
              returnIds
            );
            if (stockRows.length !== returnIds.length) fail("An order item is no longer linked to inventory.", 409);
            for (var returnedId of returnIds) {
              await editConnection.query("UPDATE food_items SET stock = stock + ? WHERE id = ?", [
                returnAmounts[String(returnedId)], returnedId
              ]);
            }
          }
          await editConnection.query(
            "UPDATE orders SET status = ?, payment_status = ?, note = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
            ["Cancelled", "Unpaid", note || null, targetOrderId]
          );
          await editConnection.commit();
          editTransactionStarted = false;
          sendJson(response, 200, { message: "Order cancelled and stock returned." });
          return;
        }

        if (!Array.isArray(changes.items) || changes.items.length < 1 || changes.items.length > 100) {
          fail("An order must have at least one item.", 400);
        }
        var newQuantities = {};
        changes.items.forEach(function(item) {
          var id = Number(item && item.food_id);
          var quantity = Number(item && item.quantity);
          if (!Number.isSafeInteger(id) || id < 1 || !Number.isInteger(quantity) || quantity < 1 || quantity > 100) {
            fail("Each item needs a valid food and quantity from 1 to 100.", 400);
          }
          if (Object.prototype.hasOwnProperty.call(newQuantities, id)) fail("An item can only appear once in the order.", 400);
          newQuantities[id] = quantity;
        });

        var oldQuantities = {};
        var oldItemDetails = {};
        oldLines.forEach(function(line) {
          if (line.food_id != null) {
            var oldKey = String(line.food_id);
            oldQuantities[oldKey] = (oldQuantities[oldKey] || 0) + Number(line.quantity);
            if (!oldItemDetails[oldKey]) oldItemDetails[oldKey] = line;
          }
        });
        var foodIds = Array.from(new Set(Object.keys(oldQuantities).concat(Object.keys(newQuantities))))
          .map(Number).sort(function(a, b) { return a - b; });
        var stockById = {};
        if (foodIds.length) {
          var foodMarks = foodIds.map(function() { return "?"; }).join(",");
          var lockedFoods = await editConnection.query(
            "SELECT id, name, price, stock FROM food_items WHERE id IN (" + foodMarks + ") ORDER BY id FOR UPDATE",
            foodIds
          );
          lockedFoods.forEach(function(food) { stockById[String(food.id)] = food; });
          if (lockedFoods.length !== foodIds.length) fail("A selected food item no longer exists.", 409);
        }

        var updatedItems = [];
        var totalCents = 0;
        Object.keys(newQuantities).forEach(function(id) {
          var food = stockById[id];
          var oldItem = oldItemDetails[id];
          var savedName = oldItem ? oldItem.item_name : food.name;
          var savedPrice = oldItem ? Number(oldItem.unit_price) : Number(food.price);
          var priceCents = Math.round(savedPrice * 100);
          var subtotalCents = priceCents * newQuantities[id];
          totalCents += subtotalCents;
          updatedItems.push({
            food_id: Number(id), item_name: savedName, quantity: newQuantities[id],
            unit_price: savedPrice.toFixed(2), subtotal: (subtotalCents / 100).toFixed(2)
          });
        });

        for (var stockId of foodIds) {
          var stockKey = String(stockId);
          var difference = (newQuantities[stockKey] || 0) - (oldQuantities[stockKey] || 0);
          if (difference > Number(stockById[stockKey].stock)) {
            fail("Not enough stock for " + stockById[stockKey].name + ". Available stock: " +
              stockById[stockKey].stock + ".", 409);
          }
          if (difference > 0) {
            await editConnection.query(
              "UPDATE food_items SET stock = stock - ? WHERE id = ? AND stock >= ?",
              [difference, stockId, difference]
            );
          } else if (difference < 0) {
            await editConnection.query("UPDATE food_items SET stock = stock + ? WHERE id = ?", [-difference, stockId]);
          }
        }
        await editConnection.query("DELETE FROM order_items WHERE order_id = ?", [targetOrderId]);
        for (var savedItem of updatedItems) {
          await editConnection.query(
            "INSERT INTO order_items (order_id, food_id, item_name, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?, ?)",
            [targetOrderId, savedItem.food_id, savedItem.item_name, savedItem.quantity, savedItem.unit_price, savedItem.subtotal]
          );
        }
        await editConnection.query(
          "UPDATE orders SET total_amount = ?, status = ?, payment_status = ?, note = ?, " +
          "updated_at = CURRENT_TIMESTAMP WHERE id = ?",
          [(totalCents / 100).toFixed(2), changes.status, changes.payment_status, note || null, targetOrderId]
        );
        await editConnection.commit();
        editTransactionStarted = false;
        sendJson(response, 200, { message: "Order changes saved." });
        return;
      } catch (error) {
        if (editTransactionStarted) await editConnection.rollback();
        throw error;
      } finally {
        editConnection.release();
      }
    }

    if (request.method === "GET" && url.pathname === "/api/inventory") {
      if (!requireRole(request, response, "admin")) return;
      var inventorySearch = buildSearch("inventory", url);
      var inventory = await pool.query(
        "SELECT f.id, f.name, f.price, f.stock, f.low_stock_level, f.category, " +
        "CASE WHEN f.stock <= f.low_stock_level THEN 'Low' ELSE 'OK' END AS status " +
        "FROM food_items f WHERE 1 = 1" + inventorySearch.sql + " ORDER BY f.name",
        inventorySearch.params
      );
      sendJson(response, 200, inventory);
      return;
    }

    if (request.method === "POST" && url.pathname === "/api/inventory") {
      if (!requireRole(request, response, "admin")) return;
      var newItem = inventoryItemFrom(await readBody(request));
      var insertItem = await pool.query(
        "INSERT INTO food_items (name, price, stock, low_stock_level, category) VALUES (?, ?, ?, ?, ?)",
        [newItem.name, newItem.price, newItem.stock, newItem.low_stock_level, newItem.category]
      );
      sendJson(response, 201, { message: "Inventory item added.", id: Number(insertItem.insertId) });
      return;
    }

    var inventoryMatch = url.pathname.match(/^\/api\/inventory\/(\d+)$/);
    if ((request.method === "PUT" || request.method === "DELETE") && inventoryMatch) {
      if (!requireRole(request, response, "admin")) return;
      var inventoryId = Number(inventoryMatch[1]);
      if (!Number.isSafeInteger(inventoryId) || inventoryId < 1) fail("Invalid inventory item.", 400);
      if (request.method === "DELETE") {
        var references = await pool.query("SELECT COUNT(*) AS total FROM order_items WHERE food_id = ?", [inventoryId]);
        if (Number(references[0].total) > 0) {
          fail("This item is used by an order and cannot be deleted. You can set its stock to 0 instead.", 409);
        }
        var deleted = await pool.query("DELETE FROM food_items WHERE id = ?", [inventoryId]);
        if (!deleted.affectedRows) fail("Inventory item not found.", 404);
        sendJson(response, 200, { message: "Inventory item deleted." });
        return;
      }

      var updatedItem = inventoryItemFrom(await readBody(request));
      var changedItem = await pool.query(
        "UPDATE food_items SET name = ?, price = ?, stock = ?, low_stock_level = ?, category = ? WHERE id = ?",
        [updatedItem.name, updatedItem.price, updatedItem.stock, updatedItem.low_stock_level, updatedItem.category, inventoryId]
      );
      if (!changedItem.affectedRows) {
        var exists = await pool.query("SELECT id FROM food_items WHERE id = ?", [inventoryId]);
        if (!exists.length) fail("Inventory item not found.", 404);
      }
      sendJson(response, 200, { message: "Inventory item updated." });
      return;
    }

    if (request.method === "GET" && url.pathname === "/api/admin/export/sales") {
      if (!requireRole(request, response, "admin")) return;
      var exportSalesSearch = buildSearch("sales", url);
      var exportSales = await pool.query(
        "SELECT s.sale_date, s.total_amount FROM (" +
        "SELECT DATE(order_date) AS sale_date, SUM(total_amount) AS total_amount " +
        "FROM orders WHERE status <> ? GROUP BY DATE(order_date)" +
        ") s WHERE 1 = 1" + exportSalesSearch.sql + " ORDER BY s.sale_date DESC",
        ["Cancelled"].concat(exportSalesSearch.params)
      );
      var salesCsvRows = [["Date", "Total Amount"]];
      exportSales.forEach(function(row) {
        salesCsvRows.push([row.sale_date, row.total_amount]);
      });
      sendCsv(response, "sales", salesCsvRows);
      return;
    }

    if (request.method === "GET" && url.pathname === "/api/admin/export/orders") {
      if (!requireRole(request, response, "admin")) return;
      var exportOrderSearch = buildSearch("adminOrders", url);
      var exportOrders = await pool.query(
        "SELECT o.id AS order_number, o.order_date, u.user_code AS student_id, u.full_name AS student_name, " +
        "GROUP_CONCAT(CONCAT(oi.quantity, 'x ', oi.item_name) ORDER BY oi.id SEPARATOR '; ') AS item_summary, " +
        "o.total_amount, o.payment_status, o.status " +
        "FROM orders o JOIN users u ON u.id = o.user_id " +
        "LEFT JOIN order_items oi ON oi.order_id = o.id WHERE 1 = 1" + exportOrderSearch.sql +
        " GROUP BY o.id, o.order_date, u.user_code, u.full_name, o.total_amount, o.payment_status, o.status " +
        "ORDER BY o.order_date DESC, o.id DESC",
        exportOrderSearch.params
      );
      var orderCsvRows = [["Order No.", "Date", "Student ID", "Student Name", "Items", "Total", "Payment", "Status"]];
      exportOrders.forEach(function(row) {
        orderCsvRows.push([
          row.order_number, row.order_date, row.student_id, row.student_name, row.item_summary,
          row.total_amount, row.payment_status, row.status
        ]);
      });
      sendCsv(response, "orders", orderCsvRows);
      return;
    }

    if (request.method === "GET" && url.pathname === "/api/sales") {
      if (!requireRole(request, response, "admin")) return;
      var salesSearch = buildSearch("sales", url);
      var sales = await pool.query(
        "SELECT s.sale_date, s.total_amount FROM (" +
        "SELECT DATE(order_date) AS sale_date, SUM(total_amount) AS total_amount " +
        "FROM orders WHERE status <> 'Cancelled' GROUP BY DATE(order_date)" +
        ") s WHERE 1 = 1" + salesSearch.sql + " ORDER BY s.sale_date DESC",
        salesSearch.params
      );
      sendJson(response, 200, sales);
      return;
    }

    if (request.method === "GET" && url.pathname === "/api/users") {
      if (!requireRole(request, response, "admin")) return;
      var filter = url.searchParams.get("role") || "all";
      if (!["all", "student", "admin"].includes(filter)) fail("Hindi valid ang filter.", 400);
      var userSearch = buildSearch("users", url);
      var roleCondition = filter === "all" ? "" : " AND u.role = ?";
      var userParams = userSearch.params.slice();
      if (filter !== "all") userParams.push(filter);
      var users = filter === "all"
        ? await pool.query("SELECT u.user_code, u.full_name, u.email, u.role, u.created_at FROM users_view u WHERE 1 = 1" + userSearch.sql + " ORDER BY u.user_code", userParams)
        : await pool.query("SELECT u.user_code, u.full_name, u.email, u.role, u.created_at FROM users_view u WHERE 1 = 1" + userSearch.sql + roleCondition + " ORDER BY u.user_code", userParams);
      sendJson(response, 200, users);
      return;
    }

    var accountMatch = url.pathname.match(/^\/api\/users\/([A-Z]{3}-\d{4})$/i);
    if ((request.method === "PUT" || request.method === "DELETE") && accountMatch) {
      var accountAdmin = requireRole(request, response, "admin");
      if (!accountAdmin) return;
      var targetCode = accountMatch[1].toUpperCase();

      if (request.method === "PUT") {
        var accountChanges = await readBody(request);
        if (!accountChanges || typeof accountChanges !== "object" || Array.isArray(accountChanges)) {
          fail("Enter valid account details.", 400);
        }
        var accountName = typeof accountChanges.full_name === "string" ? accountChanges.full_name.trim() : "";
        var accountEmail = typeof accountChanges.email === "string" ? accountChanges.email.trim().toLowerCase() : "";
        var accountRole = accountChanges.role;
        var newPassword = accountChanges.new_password;
        if (!accountName || accountName.length > 120) fail("Full name is required and must be 120 characters or fewer.", 400);
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(accountEmail) || accountEmail.length > 254) {
          fail("Enter a valid email address.", 400);
        }
        if (accountRole !== "student" && accountRole !== "admin") fail("Choose a valid account role.", 400);
        if (newPassword != null && newPassword !== "" &&
            (typeof newPassword !== "string" || newPassword.length < 8 || newPassword.length > 128)) {
          fail("A new password must be between 8 and 128 characters.", 400);
        }
        var accountPasswordHash = newPassword ? await makePassword(newPassword) : "";
        var accountConnection = await pool.getConnection();
        var accountTransactionStarted = false;
        var changedRole = false;
        try {
          await accountConnection.beginTransaction();
          accountTransactionStarted = true;
          var targetAccounts = await accountConnection.query(
            "SELECT id, role FROM users WHERE user_code = ? FOR UPDATE",
            [targetCode]
          );
          if (!targetAccounts.length) fail("Account not found.", 404);
          var targetAccount = targetAccounts[0];
          changedRole = targetAccount.role !== accountRole;
          if (Number(targetAccount.id) === Number(accountAdmin.session.id) && accountRole !== "admin") {
            fail("You cannot change your own role.", 409);
          }
          if (targetAccount.role === "admin" && accountRole === "student") {
            var adminsLeft = await accountConnection.query(
              "SELECT id FROM users WHERE role = ? FOR UPDATE",
              ["admin"]
            );
            if (adminsLeft.length <= 1) fail("The last admin cannot be changed to a student.", 409);
          }
          var matchingEmails = await accountConnection.query(
            "SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1",
            [accountEmail, targetAccount.id]
          );
          if (matchingEmails.length) fail("Email is already used by another account.", 409);

          try {
            if (accountPasswordHash) {
              await accountConnection.query(
                "UPDATE users SET full_name = ?, email = ?, role = ?, password = ? WHERE id = ?",
                [accountName, accountEmail, accountRole, accountPasswordHash, targetAccount.id]
              );
            } else {
              await accountConnection.query(
                "UPDATE users SET full_name = ?, email = ?, role = ? WHERE id = ?",
                [accountName, accountEmail, accountRole, targetAccount.id]
              );
            }
          } catch (error) {
            if (error.code === "ER_DUP_ENTRY") fail("Email is already used by another account.", 409);
            throw error;
          }
          await accountConnection.commit();
          accountTransactionStarted = false;
        } catch (error) {
          if (accountTransactionStarted) await accountConnection.rollback();
          throw error;
        } finally {
          accountConnection.release();
        }
        if (changedRole) {
          for (var entry of sessions.entries()) {
            if (Number(entry[1].id) === Number(targetAccount.id)) sessions.delete(entry[0]);
          }
        }
        sendJson(response, 200, { message: "Account updated." });
        return;
      }

      var deleteConnection = await pool.getConnection();
      var deleteTransactionStarted = false;
      try {
        await deleteConnection.beginTransaction();
        deleteTransactionStarted = true;
        var studentAccount = await deleteConnection.query(
          "SELECT id FROM users WHERE user_code = ? AND role = ? FOR UPDATE",
          [targetCode, "student"]
        );
        if (!studentAccount.length) fail("Hindi makita ang student account.", 404);
        var accountId = studentAccount[0].id;
        var orderHistory = await deleteConnection.query(
          "SELECT COUNT(*) AS total FROM orders WHERE user_id = ?",
          [accountId]
        );
        var oldOrderHistory = await deleteConnection.query(
          "SELECT COUNT(*) AS total FROM student_orders WHERE user_id = ?",
          [accountId]
        );
        if (Number(orderHistory[0].total) + Number(oldOrderHistory[0].total) > 0) {
          fail("This student has orders. Order history must be kept.", 409);
        }
        await deleteConnection.query("DELETE FROM users WHERE id = ? AND role = ?", [accountId, "student"]);
        await deleteConnection.commit();
        deleteTransactionStarted = false;
      } catch (error) {
        if (deleteTransactionStarted) await deleteConnection.rollback();
        throw error;
      } finally {
        deleteConnection.release();
      }
      sendJson(response, 200, { message: "Student account deleted." });
      return;
    }

    sendJson(response, 404, { error: "Hindi makita ang page o API route." });
  } catch (error) {
    console.error("Request error:", error.message);
    if (!response.headersSent) {
      var status = error.code === "ER_DUP_ENTRY" ? 409 : error.status || 500;
      var message = error.code === "ER_DUP_ENTRY" ? "Ginagamit na ang email o User ID." : status < 500 ? error.message : "May problema sa server. Tingnan ang terminal.";
      sendJson(response, status, { error: message });
    }
  }
}

async function start() {
  if (!/^[a-z0-9_]+$/i.test(DB_NAME)) throw new Error("Invalid database name.");
  var setup = await mariadb.createConnection({ host: DB_HOST, user: DB_USER, password: DB_PASSWORD });
  await setup.query("CREATE DATABASE IF NOT EXISTS `" + DB_NAME + "`");
  await setup.end();
  pool = mariadb.createPool({ host: DB_HOST, user: DB_USER, password: DB_PASSWORD, database: DB_NAME, connectionLimit: 5 });

  // Ginagawa ang table para sa mga user.
  await pool.query("CREATE TABLE IF NOT EXISTS users (" +
    "id INT AUTO_INCREMENT PRIMARY KEY, " +
    "user_code VARCHAR(20) NOT NULL UNIQUE, " +
    "full_name VARCHAR(120) NOT NULL, " +
    "email VARCHAR(254) NOT NULL UNIQUE, " +
    "password VARCHAR(200) NOT NULL, " +
    "role ENUM('student','admin') NOT NULL, " +
    "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP" +
    ") ENGINE=InnoDB");
  await pool.query("CREATE OR REPLACE VIEW users_view AS " +
    "SELECT user_code, full_name, email, role, created_at FROM users");

  // This table holds the menu and the stock count.
  await pool.query("CREATE TABLE IF NOT EXISTS food_items (" +
    "id INT AUTO_INCREMENT PRIMARY KEY, " +
    "name VARCHAR(120) NOT NULL, " +
    "price DECIMAL(10,2) NOT NULL, " +
    "stock INT NOT NULL DEFAULT 0, " +
    "low_stock_level INT NOT NULL DEFAULT 5" +
    ") ENGINE=InnoDB");
  await pool.query("ALTER TABLE food_items ADD COLUMN IF NOT EXISTS category VARCHAR(30) NOT NULL DEFAULT 'Meals'");

  // Ginagawa ang table para sa order ng bawat student.
  await pool.query("CREATE TABLE IF NOT EXISTS student_orders (" +
    "id INT AUTO_INCREMENT PRIMARY KEY, " +
    "user_id INT NOT NULL, " +
    "item_name VARCHAR(120) NOT NULL, " +
    "quantity INT NOT NULL, " +
    "food_id INT NULL, " +
    "total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00, " +
    "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, " +
    "FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE" +
    ") ENGINE=InnoDB");
  await pool.query("ALTER TABLE student_orders ADD COLUMN IF NOT EXISTS food_id INT NULL");
  await pool.query("ALTER TABLE student_orders ADD COLUMN IF NOT EXISTS total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00");

  await pool.query("CREATE TABLE IF NOT EXISTS orders (" +
    "id INT AUTO_INCREMENT PRIMARY KEY, " +
    "user_id INT NOT NULL, " +
    "total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00, " +
    "order_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, " +
    "status ENUM('Pending','Preparing','Ready','Completed','Cancelled') NOT NULL DEFAULT 'Pending', " +
    "payment_status ENUM('Unpaid','Paid') NOT NULL DEFAULT 'Unpaid', " +
    "note VARCHAR(200) NULL, " +
    "updated_at DATETIME NULL, " +
    "FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE" +
    ") ENGINE=InnoDB");
  await pool.query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS status ENUM('Pending','Preparing','Ready','Completed','Cancelled') NOT NULL DEFAULT 'Pending'");
  await pool.query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_status ENUM('Unpaid','Paid') NOT NULL DEFAULT 'Unpaid'");
  await pool.query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS note VARCHAR(200) NULL");
  await pool.query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL");
  await pool.query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS user_id INT NULL");
  await pool.query("CREATE TABLE IF NOT EXISTS order_items (" +
    "id INT AUTO_INCREMENT PRIMARY KEY, " +
    "order_id INT NOT NULL, " +
    "food_id INT NULL, " +
    "item_name VARCHAR(120) NOT NULL, " +
    "quantity INT NOT NULL, " +
    "unit_price DECIMAL(10,2) NOT NULL, " +
    "subtotal DECIMAL(10,2) NOT NULL, " +
    "FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE, " +
    "FOREIGN KEY (food_id) REFERENCES food_items(id) ON DELETE SET NULL" +
    ") ENGINE=InnoDB");
  await pool.query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS food_id INT NULL");
  await pool.query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS item_name VARCHAR(120) NOT NULL DEFAULT ''");
  await pool.query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS quantity INT NOT NULL DEFAULT 1");
  await pool.query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00");
  await pool.query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00");

  var foodCount = await pool.query("SELECT COUNT(*) AS amount FROM food_items");
  if (Number(foodCount[0].amount) === 0) {
    await pool.query(
      "INSERT INTO food_items (name, price, stock, low_stock_level, category) VALUES " +
      "('Chicken Rice', 75.00, 20, 5, 'Meals'), ('Pancit', 50.00, 15, 5, 'Meals'), " +
      "('Siomai', 35.00, 25, 5, 'Snacks'), ('Cheese Sandwich', 40.00, 12, 5, 'Snacks'), " +
      "('Bottled Water', 20.00, 30, 8, 'Drinks')"
    );
  }

  await pool.query(
    "UPDATE order_items oi JOIN food_items f ON f.id = oi.food_id " +
    "SET oi.item_name = CASE WHEN oi.item_name = '' THEN f.name ELSE oi.item_name END, " +
    "oi.unit_price = CASE WHEN oi.unit_price = 0 THEN f.price ELSE oi.unit_price END"
  );

  // Move old one-item orders into the new order tables without removing old rows.
  await pool.query(
    "UPDATE orders o JOIN student_orders s ON s.id = o.id " +
    "SET o.user_id = s.user_id WHERE o.user_id IS NULL"
  );
  await pool.query(
    "INSERT IGNORE INTO orders (id, user_id, total_amount, order_date) " +
    "SELECT id, user_id, total_amount, created_at FROM student_orders"
  );
  await pool.query(
    "INSERT INTO order_items (order_id, food_id, item_name, quantity, unit_price, subtotal) " +
    "SELECT s.id, f.id, s.item_name, s.quantity, " +
    "CASE WHEN f.id IS NOT NULL THEN f.price WHEN s.quantity > 0 THEN ROUND(s.total_amount / s.quantity, 2) ELSE 0.00 END, " +
    "s.total_amount " +
    "FROM student_orders s JOIN orders o ON o.id = s.id " +
    "LEFT JOIN food_items f ON f.id = s.food_id " +
    "WHERE NOT EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = s.id)"
  );

  // Ginagawa ang table para hindi maulit ang mga ID kapag may binurang account.
  await pool.query("CREATE TABLE IF NOT EXISTS id_sequences (" +
    "role ENUM('student','admin') PRIMARY KEY, " +
    "next_number INT NOT NULL" +
    ") ENGINE=InnoDB");

  for (var role of ["student", "admin"]) {
    var lastCode = await pool.query(
      "SELECT COALESCE(MAX(CAST(SUBSTRING(user_code, 5) AS UNSIGNED)), 0) + 1 AS next_number FROM users WHERE role = ?",
      [role]
    );
    await pool.query("INSERT IGNORE INTO id_sequences (role, next_number) VALUES (?, ?)", [role, Number(lastCode[0].next_number)]);
  }

  http.createServer(handleRequest).listen(PORT, function() {
    console.log("KantEase running at http://localhost:" + PORT);
  });
}

start().catch(function(error) {
  console.error("Hindi masimulan ang app:", error.message);
  process.exitCode = 1;
});