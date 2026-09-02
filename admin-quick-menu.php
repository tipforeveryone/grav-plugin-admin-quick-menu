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
        ];
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
     * Chèn CSS + markup menu trực tiếp vào output (theme không render Grav
     * Assets, xem ghi chú trong in-place-edit-button plugin).
     */
    public function onOutputGenerated(): void
    {
        if (!$this->canEdit()) {
            return;
        }

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

        $this->grav->output = $output;
    }

    private function renderMenu(): string
    {
        $adminRoute = trim((string) $this->grav['config']->get('plugins.admin.route', '/admin'), '/');
        $root = rtrim($this->grav['uri']->rootUrl(false), '/');

        $adminUrl = $root . '/' . $adminRoute;
        $shortcuts = $this->buildShortcuts($root, $adminRoute);
        $customLinks = $this->buildCustomLinks($root);

        return $this->grav['twig']->processTemplate('partials/quick-menu.html.twig', [
            'admin_url'    => $adminUrl,
            'shortcuts'    => $shortcuts,
            'custom_links' => $customLinks,
        ]);
    }

    /**
     * Nhóm "Thêm mới": mỗi item submit thẳng vào cơ chế Add Page có sẵn của
     * Grav Admin (task=continue) để pre-fill route (thư mục cha) + template.
     */
    private function buildShortcuts(string $root, string $adminRoute): array
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

            $items[] = [
                'label'       => $label,
                'template'    => $template,
                'parent_path' => $parentPath,
                'action_url'  => $root . '/' . $adminRoute . '/pages' . $parentPath,
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
