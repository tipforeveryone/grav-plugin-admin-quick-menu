<?php

namespace Grav\Plugin;

use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;

/**
 * Admin Quick Menu
 *
 * Icon nổi góc trên-phải frontend, chỉ hiện cho admin đã đăng nhập. Click ra
 * menu 3 nhóm: "Trang quản trị" (cố định), "Thêm mới" (shortcut tạo bài viết
 * đã cấu hình sẵn label + thư mục cha + template) và "Liên kết" (link tĩnh
 * tùy chỉnh). Mỗi shortcut "Thêm mới" là 1 form HTML submit thẳng vào cơ chế
 * "Add Page" có sẵn của Grav Admin (task=continue), không cần JS/AJAX.
 *
 * Bên trong Admin Panel, cùng danh sách shortcut "Thêm mới" đó còn được lặp
 * lại thành 1 mục "Quick Add Content" trên menu trái (xem onAdminMenu() +
 * admin/templates/quick-add-content.html.twig), để không cần rời khỏi admin
 * ra frontend mới bấm được nút tạo nhanh.
 */
class AdminQuickMenuPlugin extends Plugin
{
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onApiSidebarItems'    => ['onApiSidebarItems', 0],
        ];
    }

    /**
     * Admin2 counterpart to onAdminMenu() below: registers the same "Quick
     * Add Content" entry in the admin-next sidebar. The page itself is a
     * component-mode plugin page (see admin-next/pages/admin-quick-menu.js),
     * auto-discovered by the api plugin's GpmController — no onApiPluginPageInfo
     * handler needed.
     */
    public function onApiSidebarItems(Event $event): void
    {
        if (!$this->config->get('plugins.admin-quick-menu.enabled', true)) {
            return;
        }

        $items = $event['items'] ?? [];
        $items[] = [
            'id'        => 'admin-quick-menu',
            'plugin'    => 'admin-quick-menu',
            'label'     => 'Quick Add Content',
            'icon'      => 'fa-plus-circle',
            'route'     => '/plugin/admin-quick-menu',
            'priority'  => 80,
            'authorize' => ['api.pages', 'api.super'],
        ];
        $event['items'] = $items;
    }

    public function onPluginsInitialized(): void
    {
        if ($this->isAdmin()) {
            $this->enable([
                'onAdminMenu'              => ['onAdminMenu', 0],
                'onAdminTwigTemplatePaths' => ['onAdminTwigTemplatePaths', 0],
                'onTwigSiteVariables'      => ['onTwigSiteVariables', 0],
            ]);

            return;
        }

        $this->enable([
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onOutputGenerated'   => ['onOutputGenerated', 0],
        ]);
    }

    public function onAdminMenu(): void
    {
        $this->grav['twig']->plugins_hooked_nav['Quick Add Content'] = [
            'route'     => 'quick-add-content',
            'icon'      => 'fa-plus-circle',
            'authorize' => ['admin.pages', 'admin.super'],
            'priority'  => 80,
        ];
    }

    public function onAdminTwigTemplatePaths(Event $event): void
    {
        $paths = $event['paths'];
        $paths[] = __DIR__ . '/admin/templates';
        $event['paths'] = $paths;
    }

    public function onTwigSiteVariables(): void
    {
        /** @var \Grav\Plugin\Admin|null $admin */
        $admin = $this->grav['admin'] ?? null;
        if (!$admin || $admin->location !== 'quick-add-content') {
            return;
        }

        $this->grav['assets']->addCss('plugin://admin-quick-menu/css/admin-quick-add.css');

        $adminRoute = trim((string) $this->grav['config']->get('plugins.admin.route', '/admin'), '/');
        $root = rtrim($this->grav['uri']->rootUrl(false), '/');

        $this->grav['twig']->twig_vars['quick_add_shortcuts'] = $this->buildShortcuts($root, $adminRoute);
    }

    public function onTwigTemplatePaths(): void
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Chèn CSS + markup menu + bridge JS admin2 trực tiếp vào output (theme
     * không render Grav Assets, xem ghi chú trong in-place-edit-button plugin).
     *
     * Trước đây gate bằng canEdit() (session cổ điển). Giờ luôn chèn: renderMenu()
     * tự quyết định render menu ở dạng "pending" (ẩn qua CSS) khi canEdit() false,
     * để bridge JS bên dưới có thể mở khóa cho user chỉ đăng nhập qua admin2/JWT
     * — session đó không hề chạm tới canEdit() ở đây. Cùng cơ chế và cùng đánh
     * đổi đã ghi chú trong in-place-edit-button.php.
     *
     * js/admin2-bridge.js tồn tại y hệt bên plugin in-place-edit-button (2
     * plugin cùng cần) — dedup-guard bằng cách check substring trước khi chèn,
     * để cài cả 2 plugin không bị load script trùng 2 lần.
     */
    public function onOutputGenerated(): void
    {
        $output = $this->grav->output;
        if (strpos($output, '</head>') === false || strpos($output, '</body>') === false) {
            return;
        }

        $base = rtrim($this->grav['uri']->rootUrl(false), '/');
        $href = $base . '/user/plugins/admin-quick-menu/css/admin-quick-menu.css';

        $cssFile = __DIR__ . '/css/admin-quick-menu.css';
        if (is_file($cssFile)) {
            $href .= '?v=' . filemtime($cssFile);
        }

        $link = '<link rel="stylesheet" href="' . $href . '">';
        $output = str_replace('</head>', $link . "\n</head>", $output);

        $menu = $this->renderMenu();
        if ($menu !== '') {
            $output = str_replace('</body>', $menu . "\n</body>", $output);
        }

        if (strpos($output, 'admin2-bridge.js') === false) {
            $jsHref = $base . '/user/plugins/admin-quick-menu/js/admin2-bridge.js';
            $jsFile = __DIR__ . '/js/admin2-bridge.js';
            if (is_file($jsFile)) {
                $jsHref .= '?v=' . filemtime($jsFile);
            }
            $script = '<script src="' . $jsHref . '"></script>';
            $output = str_replace('</body>', $script . "\n</body>", $output);
        }

        $this->grav->output = $output;
    }

    private function renderMenu(): string
    {
        $adminRoute = trim((string) $this->grav['config']->get('plugins.admin.route', '/admin'), '/');
        $root = rtrim($this->grav['uri']->rootUrl(false), '/');

        $adminUrl = $root . '/' . $adminRoute;
        // Dashboard gốc của admin2 (route "/" trong manifest SPA — xem
        // user/plugins/admin2/app/_app/immutable/entry/app.*.js). Dùng cho cả
        // link "Trang quản trị" tĩnh lẫn base URL của các shortcut "Thêm mới".
        $admin2Route = trim((string) $this->grav['config']->get('plugins.admin2.route', '/admin2'), '/');
        $admin2Url = $root . '/' . $admin2Route;
        $shortcuts = $this->buildShortcuts($root, $adminRoute, $admin2Route);
        $customLinks = $this->buildCustomLinks($root);

        return $this->grav['twig']->processTemplate('partials/quick-menu.html.twig', [
            'admin_url'    => $adminUrl,
            'admin2_url'   => $admin2Url,
            'shortcuts'    => $shortcuts,
            'custom_links' => $customLinks,
            'pending'      => !$this->canEdit(),
        ]);
    }

    /**
     * Nhóm "Thêm mới": mỗi item submit thẳng vào cơ chế Add Page có sẵn của
     * Grav Admin (task=continue) để pre-fill route (thư mục cha) + template.
     *
     * admin2_url song song cho SPA admin2: route "/pages/new" của nó (node
     * 19 trong manifest SPA — xem user/plugins/admin2/app/_app/immutable/
     * nodes/19.*.js) tự đọc 3 query-param `parent`, `template`, `title` để
     * pre-fill y hệt — đã dò trực tiếp trong bundle đã compile (tìm thấy
     * `Q.get("parent")||"/"`, `Q.get("template")||"default"`), không phải
     * đoán. Không dùng nonce/POST vì đây là điều hướng GET thường của SPA.
     */
    private function buildShortcuts(string $root, string $adminRoute, string $admin2Route): array
    {
        $shortcuts = (array) $this->grav['config']->get('plugins.admin-quick-menu.menu_shortcuts', []);

        $items = [];
        foreach ($shortcuts as $shortcut) {
            $label = trim((string) ($shortcut['label'] ?? ''));
            $template = trim((string) ($shortcut['template'] ?? ''));
            if ($label === '' || $template === '') {
                continue;
            }

            $parentPath = trim((string) ($shortcut['parent_path'] ?? ''));
            $parentPath = $parentPath !== '' ? '/' . trim($parentPath, '/') : '';

            $admin2Query = http_build_query([
                'parent'   => $parentPath !== '' ? $parentPath : '/',
                'template' => $template,
                'title'    => $label,
            ]);

            $items[] = [
                'label'       => $label,
                'template'    => $template,
                'parent_path' => $parentPath,
                'action_url'  => $root . '/' . $adminRoute . '/pages' . $parentPath,
                'admin2_url'  => $root . '/' . $admin2Route . '/pages/new?' . $admin2Query,
            ];
        }

        return $items;
    }

    /**
     * Nhóm "Liên kết tùy chỉnh": link tĩnh, chỉ gồm tên + đường dẫn tương đối.
     */
    private function buildCustomLinks(string $root): array
    {
        $links = (array) $this->grav['config']->get('plugins.admin-quick-menu.custom_links', []);

        $items = [];
        foreach ($links as $link) {
            $label = trim((string) ($link['label'] ?? ''));
            $url = trim((string) ($link['url'] ?? ''));
            if ($label === '' || $url === '') {
                continue;
            }

            $items[] = [
                'label' => $label,
                'url'   => $root . '/' . ltrim($url, '/'),
            ];
        }

        return $items;
    }

    /**
     * True nếu user đã đăng nhập và có quyền sửa page trong admin.
     * Copy nguyên logic từ in-place-edit-button plugin (xem canEdit() ở đó
     * để biết lý do dùng 'admin.login' làm tín hiệu trên frontend).
     */
    private function canEdit(): bool
    {
        $user = $this->grav['user'] ?? null;
        if (!$user || !$user->authenticated) {
            return false;
        }

        return $user->authorize('admin.login') === true
            || $user->authorize('admin.super') === true
            || $user->authorize('admin.pages') === true;
    }
}
