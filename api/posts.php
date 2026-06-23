<?php
// =========================================
// api/posts.php  —  文章接口
// GET /api/posts.php              → 最新文章列表
// GET /api/posts.php?id=1         → 单篇文章详情
// GET /api/posts.php?search=关键词 → 搜索
// GET /api/posts.php?category=design → 按分类筛选
// =========================================
require_once __DIR__ . '/../includes/db.php';

$pdo = db();

// ---- 单篇文章 ----
if (!empty($_GET['id'])) {
    $stmt = $pdo->prepare(
        'SELECT p.*, c.name AS category_name, col.title AS collection_title
         FROM posts p
         LEFT JOIN categories  c   ON c.id  = p.category_id
         LEFT JOIN collections col ON col.id = p.collection_id
         WHERE p.id = ? AND p.is_published = 1'
    );
    $stmt->execute([(int)$_GET['id']]);
    $post = $stmt->fetch();
    if (!$post) json_out(['error' => '文章不存在'], 404);
    json_out($post);
}

// ---- 搜索（按相关度排序） ----
if (!empty($_GET['search'])) {
    $q  = trim($_GET['search']);
    $kw = '%' . $q . '%';
    // 拉取候选文章（标题/摘要/正文/分类名 命中）
    $stmt = $pdo->prepare(
        'SELECT p.id, p.title, p.summary, p.cover_url, p.published_at,
                c.name AS category_name, c.slug AS category_slug,
                col.title AS collection_title
         FROM posts p
         LEFT JOIN categories  c   ON c.id  = p.category_id
         LEFT JOIN collections col ON col.id = p.collection_id
         WHERE p.is_published = 1
           AND (p.title    LIKE ?
             OR p.summary  LIKE ?
             OR p.content  LIKE ?
             OR c.name     LIKE ?
             OR col.title  LIKE ?)
         LIMIT 40'
    );
    $stmt->execute([$kw, $kw, $kw, $kw, $kw]);
    $rows = $stmt->fetchAll();

    // 客户端相关度评分
    $ql = mb_strtolower($q);
    foreach ($rows as &$row) {
        $score = 0;
        $tl  = mb_strtolower($row['title']          ?? '');
        $sl  = mb_strtolower($row['summary']         ?? '');
        $cl  = mb_strtolower($row['category_name']   ?? '');
        $col = mb_strtolower($row['collection_title'] ?? '');

        // 完全匹配标题 +100，包含 +40
        if ($tl === $ql)                                  $score += 100;
        elseif (mb_strpos($tl, $ql) === 0)                $score +=  60;
        elseif (mb_strpos($tl, $ql) !== false)            $score +=  40;
        // 分类 / 专题命中 +25
        if (mb_strpos($cl,  $ql) !== false)               $score +=  25;
        if (mb_strpos($col, $ql) !== false)               $score +=  20;
        // 摘要命中 +10
        if (mb_strpos($sl,  $ql) !== false)               $score +=  10;

        $row['_score'] = $score;
    }
    unset($row);

    usort($rows, function($a, $b) { return $b['_score'] <=> $a['_score']; });

    // 移除内部分数字段
    $rows = array_map(function($r){ unset($r['_score']); return $r; }, $rows);
    json_out(array_slice($rows, 0, 20));
}

// ---- 按分类筛选 ----
// ---- 按专题筛选 ----
// ---- 最新文章列表（含分页与筛选） ----
$limit  = max(1, min(50, (int)($_GET['limit']  ?? 10)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

$where  = ['p.is_published = 1'];
$params = [];

if (!empty($_GET['category'])) {
    $where[]  = 'c.slug = ?';
    $params[] = $_GET['category'];
}
if (!empty($_GET['collection'])) {
    $where[]  = 'p.collection_id = ?';
    $params[] = (int)$_GET['collection'];
}

$whereSQL = implode(' AND ', $where);

// 总数
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM posts p LEFT JOIN categories c ON c.id = p.category_id WHERE $whereSQL");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

// 列表
$listParams = array_merge($params, [$limit, $offset]);
$stmt = $pdo->prepare(
    "SELECT p.id, p.title, p.summary, p.cover_url, p.published_at,
            c.name AS category_name, c.slug AS category_slug,
            col.title AS collection_title, col.id AS collection_id
     FROM posts p
     LEFT JOIN categories  c   ON c.id  = p.category_id
     LEFT JOIN collections col ON col.id = p.collection_id
     WHERE $whereSQL
     ORDER BY p.published_at DESC
     LIMIT ? OFFSET ?"
);
$stmt->execute($listParams);
json_out(['posts' => $stmt->fetchAll(), 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
