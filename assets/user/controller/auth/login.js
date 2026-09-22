!function () {
    //登录跳转地址加固：goto 来自 URL 参数，旧代码未校验即 location.href，可被构造成
    //站外地址（开放重定向、钓鱼）或 javascript: 伪协议（本站上下文 XSS）。这里只放行
    //站内相对地址：必须 / 开头、但不能是 //（协议相对→站外）、不含反斜杠与换行注入。
    function safeGoto(raw, fallback) {
        if (typeof raw !== "string" || raw === "" || raw === "null") {
            return fallback;
        }
        let target;
        try {
            target = decodeURIComponent(raw);
        } catch (e) {
            return fallback;
        }
        const safe = target.charAt(0) === "/"
            && target.charAt(1) !== "/"
            && target.indexOf("\\") === -1
            && !/[\r\n]/.test(target);
        return safe ? target : fallback;
    }

    let goto = safeGoto(util.getParam("goto"), "/");

    $(`.needs-validation`).on("submit", function (e) {
        e.preventDefault();
        const formData = new FormData($('.needs-validation')[0]);
        const data = Object.fromEntries(formData.entries());
        util.post("/user/api/authentication/login", data, res => {
            window.location.href = goto;
            message.success(res.msg);
        });
    });
}();
