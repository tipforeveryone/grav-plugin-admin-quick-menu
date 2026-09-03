/**
 * File này tồn tại y hệt (byte-for-byte) ở cả 2 plugin in-place-edit-button
 * và admin-quick-menu — cùng cách đã dùng cho canEdit() (xem ghi chú trong
 * admin-quick-menu.php: "Copy nguyên logic từ in-place-edit-button plugin").
 * Mỗi plugin tự chèn 1 lần (có dedup-guard, xem onOutputGenerated()) để cài
 * riêng lẻ plugin nào cũng chạy được, không phụ thuộc plugin còn lại.
 *
 * Admin2 auth bridge — mở khóa các widget frontend "chỉ dành cho admin"
 * (nút bút chì IPEB, Admin Quick Menu) cho user đăng nhập qua admin2 (SPA
 * SvelteKit ở /admin2, xác thực bằng JWT của plugin `api`).
 *
 * Vì sao cần file này: IPEB/Admin Quick Menu render phía PHP dựa trên session
 * cổ điển của Grav ($grav['user']->authenticated). admin2 KHÔNG hề chạm tới
 * session đó — nó đăng nhập qua POST /api/v1/auth/token, nhận JWT, và JWT chỉ
 * được SPA tự đính kèm header (X-API-Token) cho các request gọi API riêng của
 * nó, không phải cookie nên PHP xử lý trang thường (blog, trang chủ...) không
 * bao giờ thấy được. Hai widget trên vẫn render nút/menu ra HTML nhưng ở dạng
 * "pending" (ẩn qua CSS — xem .ipeb-edit-btn--pending / .aqm-menu.aqm-pending).
 * Script này tự đọc JWT admin2 đã lưu, xác thực với API, rồi gắn 1 class vào
 * <body> để CSS hiện các nút đó lên.
 *
 * Lưu ý quan trọng: đây CHỈ là "mở khóa hiển thị". Nút bút chì vẫn trỏ vào
 * /admin (admin-classic) — bấm vào mà chưa từng đăng nhập /admin thì vẫn sẽ
 * bị yêu cầu đăng nhập ở đó, vì trang admin-classic dùng session cổ điển
 * hoàn toàn khác, script này không (và không thể) bắc cầu session thật.
 */
(function () {
    "use strict";

    /** Key localStorage admin2 dùng để lưu access token — xem hàm Ln()/Yr trong
     *  user/plugins/admin2/app/_app/immutable/chunks/CA2JBzYV.js:
     *  key = "grav_admin_auth" + (basePath ? "::" + basePath : ""), basePath
     *  lấy từ window.__GRAV_CONFIG__.basePath (route của admin2, mặc định /admin2). */
    var KNOWN_KEYS = ["grav_admin_auth::/admin2", "grav_admin_auth"];

    function readStoredAccessToken() {
        try {
            for (var i = 0; i < KNOWN_KEYS.length; i++) {
                var raw = localStorage.getItem(KNOWN_KEYS[i]);
                if (raw) return extractToken(raw);
            }
            // Phòng khi route /admin2 bị đổi: quét mọi key có tiền tố này.
            for (var j = 0; j < localStorage.length; j++) {
                var key = localStorage.key(j);
                if (key && key.indexOf("grav_admin_auth") === 0) {
                    var value = localStorage.getItem(key);
                    var token = value ? extractToken(value) : null;
                    if (token) return token;
                }
            }
        } catch (e) {
            // localStorage không khả dụng (Safari private mode chặn, v.v.) -> bỏ qua lặng lẽ.
        }
        return null;
    }

    function extractToken(raw) {
        try {
            var parsed = JSON.parse(raw);
            return parsed && typeof parsed.accessToken === "string" ? parsed.accessToken : null;
        } catch (e) {
            return null;
        }
    }

    /** Giải mã phần payload của JWT (không xác minh chữ ký — chỉ để đọc `exp`
     *  phía client cho nhanh, tránh gọi API khi token đã hết hạn rõ ràng).
     *  Xác thực THẬT vẫn nằm ở lần gọi GET /me bên dưới. */
    function jwtExpiryMs(token) {
        try {
            var parts = token.split(".");
            if (parts.length < 2) return null;
            var b64 = parts[1].replace(/-/g, "+").replace(/_/g, "/");
            var padded = b64 + "=".repeat((4 - (b64.length % 4)) % 4);
            var payload = JSON.parse(atob(padded));
            return typeof payload.exp === "number" ? payload.exp * 1000 : null;
        } catch (e) {
            return null;
        }
    }

    var token = readStoredAccessToken();
    if (!token) return;

    var expiresAt = jwtExpiryMs(token);
    if (expiresAt !== null && expiresAt <= Date.now()) return;

    fetch("/api/v1/me", {
        headers: { "X-API-Token": token, "Accept": "application/json" },
        credentials: "omit"
    })
        .then(function (res) {
            return res.ok ? res.json() : null;
        })
        .then(function (body) {
            var data = body && body.data;
            if (!data) return;

            var access = data.access || {};
            // Tương đương 3 quyền admin.login/admin.super/admin.pages phía classic
            // (xem canEdit() trong in-place-edit-button.php / admin-quick-menu.php).
            // Không dùng "api.login": PermissionResolver::resolvedMap() chỉ liệt
            // kê các action thật sự đăng ký trong hệ thống Permissions (api.pages,
            // api.media, ...) — "login" không phải 1 action như vậy nên key này
            // luôn vắng mặt trong `access`, dù account có access.api.login: true.
            // "api.access" thay thế hợp lý: GET /me tự nó đã bắt buộc quyền này
            // (requirePermission('api.access')) nên response 200 tới đây tức là
            // user chắc chắn có ít nhất quyền này rồi.
            var allowed = data.super_admin === true
                || access["api.access"] === true
                || access["api.pages"] === true;

            if (allowed) {
                // Mọi phần tử được render sẵn trỏ vào admin-classic (nút bút chì
                // IPEB /admin/pages/..., link "Trang quản trị" /admin, form "Thêm
                // mới" của Admin Quick Menu submit vào task=continue) — user chỉ
                // có JWT admin2 không có session đó nên bấm/submit chỉ gặp lại
                // màn hình đăng nhập /admin. Đổi sang route thật của admin2 (đã
                // xác nhận trực tiếp qua entry route manifest + node component
                // của SPA, không phải đoán) để dùng lại luôn token đã có sẵn
                // trong localStorage — 2 kiểu phần tử cần xử lý khác nhau:
                //   - <a>: chỉ cần đổi thẳng href.
                //   - <form> (shortcut "Thêm mới"): admin2 dùng GET + query-param
                //     (?parent=&template=&title=) chứ không phải POST + nonce như
                //     admin-classic, nên phải chặn submit và tự điều hướng.
                var admin2Elements = document.querySelectorAll("[data-ipeb-admin2-href]");
                for (var i = 0; i < admin2Elements.length; i++) {
                    var el = admin2Elements[i];
                    var admin2Href = el.getAttribute("data-ipeb-admin2-href");
                    if (!admin2Href) continue;

                    if (el.tagName === "FORM") {
                        el.addEventListener("submit", (function (url) {
                            return function (event) {
                                event.preventDefault();
                                window.location.href = url;
                            };
                        })(admin2Href));
                    } else {
                        el.setAttribute("href", admin2Href);
                    }
                }
                document.body.classList.add("is-admin2-authed");
            }
        })
        .catch(function () {
            // Token hết hạn/bị thu hồi/lỗi mạng -> giữ nguyên trạng thái ẩn, không báo lỗi.
        });
})();
