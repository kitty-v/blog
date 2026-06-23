<?php
// =========================================
// api/collections.php  —  专题列表接口
// GET /api/collections.php
// =========================================
require_once __DIR__ . '/../includes/db.php';

$rows = db()
    ->query('SELECT id, title, description, cover_url FROM collections ORDER BY sort_order ASC')
    ->fetchAll();

json_out($rows);
