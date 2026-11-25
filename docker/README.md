# Docker 部署说明

生产发布、版本 tag、回滚与跨版本升级约定见 **[RELEASE.md](./RELEASE.md)**。
生产默认镜像是 `ghcr.io/wangguo1230/acg-faka:latest`；每次发布必须同时打 `X.Y.Z` 版本 tag。

本项目的 Docker 编排包含三个服务：

- `app`：PHP 8.2 + Apache，启用 `.htaccess` 伪静态。
- `mysql`：MySQL 8.0，存储商品、订单、用户、卡密、配置等核心数据。
- `redis`：Redis 7，作为 PHP Session 存储，保存登录态、验证码等临时状态。

插件相关目录已经做持久化：

- `assets/cache`：后台/用户上传文件、远程图片缓存、缩略图，以及持久化后的 `/favicon.ico`。
- `app/Plugin`：通用插件。
- `app/Pay`：支付插件。
- `app/View/User/Theme`：用户端主题模板。
- `kernel/Install`：安装锁和应用商店临时下载目录。
- `runtime`：缓存、视图缓存、运行时文件。

容器启动脚本会自动修复这些持久化目录的 `www-data` 写权限，避免应用商店显示“安装成功”但实际解压不到插件目录、上传目录无法创建、站点 Logo 无法覆盖等问题。

## 启动

```bash
docker compose up -d --build
```

默认访问地址：

```text
http://127.0.0.1:8080
```

如需修改端口：

```bash
ACG_HTTP_PORT=8081 docker compose up -d --build
```

## 首次安装填写

安装页面里的数据库信息请填写：

```text
数据库地址：mysql
数据库名称：acg_faka
数据库账号：acg
数据库密码：acg_password
数据库前缀：acg_
```

安装完成后后台地址：

```text
http://127.0.0.1:8080/admin
```

## Redis Session 验证

访问页面、登录后台或触发验证码后，可以检查 Redis Session：

```bash
docker exec -it acg-faka-redis redis-cli -n 1 keys "acg_sess:*"
```

## 支付方式金额范围（3.8.6）

在后台「支付接口」新增或编辑接口，设置「单笔最低」「单笔最高」。每个接口独立配置，商品下单和余额充值共用该范围。

- 留空或填 `0` 表示该方向不限，旧接口升级后默认不限。
- 最低、最高金额都包含边界。例如 `10`～`500`，实付 `10` 和 `500` 均可使用。
- 按站点货币的实付金额判断：商品优惠后的总价或充值金额，加上该接口手续费；不含充值赠送金额。
- 超限时前台置灰并说明原因，调整数量、规格、优惠券或充值金额后重新计算；直接请求下单接口也会被服务端拒绝。
- 系统余额支付和免费商品不受网关限额限制。修改限额不会影响已有待支付订单的正常回调。

例如固定手续费为 `1`、最高金额为 `100` 时，商品总价 `99` 可支付，`99.01` 会超限。金额最多填写两位小数；最低金额不能高于非零的最高金额。

容器启动迁移会自动为旧库补齐限额字段及 3.8.6 新增列。可用以下命令验证边界与隔离升级流程：

```bash
node --test tests/payment-limits.test.js
python3 tests/upgrade/run.py --image <本次构建的候选镜像> --platform linux/amd64
```

测试使用独立 MySQL 容器与临时目录；ARM 镜像请将 `--platform` 改为 `linux/arm64`。

## 支付与订单安全加固

会员订单的查单列表和卡密接口都会校验购买会员；未登录或登录其他账号时，不返回该会员的订单。游客订单继续使用原有联系方式、订单号和查单密码，设置密码的订单在验证前不返回卡密或发货留言。

密码登录按来源 IP、实际会员 ID 与 IP、实际会员 ID 三层限流。用户名、邮箱、手机号及数据库视为相同的字符写法共享会员计数；成功登录其他会员不会清空来源 IP 的尝试次数。

反向代理部署必须同时设置「IP 获取方式」和「受信代理」。本生产环境由 OpenResty 覆盖 `X-Real-IP`，应用只信任 Docker 网关 `172.18.0.1`，使用方式 `1`（`X-Real-IP`）。其他环境须按实际代理地址配置，避免信任所有地址或直接采用客户端可提交的请求头。关闭亚分转账蜜罐应设置 `transfer_honeypot_enabled=0`，保留原有计数以供核查。

上述订单权限和登录绕过案例包含在 `tests/upgrade/security_hardening.php` 中；升级测试会同时验证工作区代码和候选镜像内的实际代码。

对接 API 在验签前统一拒绝停用账号与空密钥。会员订单的支付状态轮询也校验买家归属，充值状态仅向充值账号本人返回；历史游客支付轮询保留原有行为。

后台密码登录同时按来源 IP 和数据库中的管理员 ID 限流，邮箱等价写法共享账号预算，成功登录不清空 IP 预算。容器迁移幂等补齐 `manage.google_secret`；管理员仍需本人在安全设置中扫码绑定两步验证，服务端不会自动代绑。

用户登录令牌设置 `HttpOnly` 与 `SameSite=Lax`，HTTPS 请求同时设置 `Secure`。存量浏览器会话在下一次有效请求时补齐这些标志，保持原到期时间，并禁止缓存认证响应。内置主题从服务端获取登录态，不读取会话 Cookie。

生产依赖由 `composer.lock` 固定，镜像构建每次同步依赖并执行 `composer audit --no-dev --locked`，发现公告则停止构建。依赖升级的 HTTP 回归覆盖登录、会话 Cookie、支付轮询、主题及支付表单模板。

## 重置本地 Docker 环境

如果需要清空 MySQL、Redis、安装锁和运行缓存，执行：

```bash
docker compose down -v
```

然后重新构建：

```bash
docker compose up -d --build
```
