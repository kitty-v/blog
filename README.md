# 昨夜书博客 —— PHP + MySQL 后端部署指南

## 目录结构

```
picker/
├── index.php          # 首页（PHP动态渲染，前端样式完全保留）
├── post.php           # 文章详情页
├── database.sql       # 数据库结构 + 初始数据
├── includes/
│   └── db.php         # 数据库连接配置
└── api/
    ├── posts.php      # 文章 REST API
    └── collections.php# 专题 REST API
```

---

## 一、导入数据库

```bash
# 方式1：命令行
mysql -u root -p < database.sql

# 方式2：phpMyAdmin / Navicat 直接导入 database.sql
```

---

## 二、配置数据库连接

编辑 `includes/db.php`，修改以下常量：

```php
define('DB_HOST', 'localhost');   // 数据库主机
define('DB_PORT', '3306');        // 端口
define('DB_NAME', 'picker_blog'); // 数据库名
define('DB_USER', 'root');        // 用户名 ← 修改
define('DB_PASS', '');            // 密码   ← 修改
```

---

## 三、服务器要求

| 环境   | 要求          |
|--------|---------------|
| PHP    | ≥ 7.4（需PDO、PDO_MySQL扩展）|
| MySQL  | ≥ 5.7 / MariaDB ≥ 10.3 |
| Web服务器 | Apache / Nginx（任意均可）|

---

## 四、Nginx 配置参考

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/picker;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

---

## 五、API 接口说明

### 文章接口 `GET /api/posts.php`

| 参数       | 说明              | 示例                          |
|------------|-------------------|-------------------------------|
| 无参数     | 最新文章列表      | `/api/posts.php`              |
| `id`       | 单篇文章详情      | `/api/posts.php?id=1`         |
| `search`   | 全文搜索          | `/api/posts.php?search=设计`  |
| `category` | 按分类slug筛选    | `/api/posts.php?category=design` |
| `limit`    | 返回条数（默认10）| `/api/posts.php?limit=5`      |
| `offset`   | 分页偏移          | `/api/posts.php?offset=10`    |

### 专题接口 `GET /api/collections.php`

返回所有专题列表，无参数。

---

## 六、数据库表说明

| 表名          | 说明     |
|---------------|----------|
| `categories`  | 文章分类 |
| `collections` | 专题系列 |
| `posts`       | 文章     |

**posts 表主要字段：**
- `title` — 标题
- `summary` — 摘要（首页展示）
- `content` — 正文（支持HTML）
- `cover_url` — 封面图URL
- `category_id` — 关联分类
- `collection_id` — 关联专题
- `published_at` — 发布日期
- `is_published` — 是否发布（1/0）

---

## 七、新增文章示例（SQL）

```sql
USE picker_blog;

INSERT INTO posts (title, summary, content, cover_url, category_id, published_at)
VALUES (
  '我的新文章标题',
  '这是文章摘要，显示在首页列表...',
  '<p>这是文章正文，支持HTML格式。</p><p>第二段落...</p>',
  'https://images.unsplash.com/photo-xxxxx',
  1,       -- 分类ID（1=设计思考）
  CURDATE()
);
```
