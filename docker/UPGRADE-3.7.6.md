# 旧站升级到 3.7.6：执行手册

本分支已实现启动迁移、旧订单回调兼容、配置保留及专用回滚镜像构建工具。2026-09-19 已将通过隔离验收的升级和兼容回滚镜像推送到 GHCR，`3.7.6` 与 `latest` 已指向本次升级修复版。此次发布未操作生产容器，服务器切换仍按下文执行。

目标基线：`9927235` 的 3.5.4 Docker 旧站，升级到合并 main 后的 3.7.6。保留原数据库、Compose 项目名及七个持久化卷。容器启动完成迁移后，原支付接口可直接用于新购买和充值，无需重装或重新填写原商户配置。

## 已实现的行为

| 情况 | 启动时的处理 |
|---|---|
| 旧接口没有配置 ID，插件文件中有凭据 | 导入数据库并绑定原接口；同一插件的多个旧接口共用迁入配置 |
| 接口已绑定有效配置，插件另有未使用的空配置 | 保持实际绑定及其内容，忽略未使用的空配置 |
| 不完整升级留下唯一的空“默认配置” | 首次完成迁移前，可从原支付文件恢复；不覆盖其他已选择的配置 |
| 非零配置 ID 不存在或属于其他插件 | 启用接口报错，禁止猜测商户号或自动更换绑定 |
| 停用/归档接口没有凭据 | 提示接口及未完成订单数量，不阻断其他业务启动 |
| 启用接口没有可确定的凭据或插件文件 | 启动失败，输出具体接口；保留旧配置供修复后重试 |

迁移补齐 3.7.6 增量表列，使用 MySQL 互斥锁和完成记录，可重复执行。连接 MySQL 默认等待 30 秒，可通过 `ACG_DB_WAIT_SECONDS` 调整到 0–120 秒。支付数据按插件提交事务；MySQL 的 DDL 已提交部分在失败后保留，下次启动继续补齐。

旧 `callback.Epay` 等地址从插件 `Info.php` 声明的字段定位订单，再核对插件归属、签名、签名后的订单号、成功状态和金额。首次迁移在独立的 `pay_legacy_callback` 表冻结旧凭据和订单 ID 范围，包含空范围。接口重新绑定或编辑当前配置不会覆盖历史凭据。新订单使用 `callback.订单号`。合法重复回调返回成功；订单行锁防止并发通知重复发货、入账。充值手续费不会计入余额。

升级先迁移，再刷新内置主题、功能插件及静态版本配置。保留数据库连接、支付文件、主题 `Setting.php`、功能插件配置和其他运行数据。支付插件置顶只改 `top`，不再删除原凭据。商品和充值的同步回跳返回用户访问的域名，异步通知使用配置的回调域名。

健康检查只探测 Apache 和关键数据库结构，不触发网关、插件通知或业务写入。后台采用新版会话，旧登录 Cookie 失效，需要重新登录，密码保留。

## 构建和隔离验收

在本仓库根目录执行，需要 Python 3 和 Docker；镜像目标为 `linux/amd64`：

```bash
docker build --platform linux/amd64 -t acg-faka:3.7.6-upgrade-fixed .
docker pull ghcr.io/wangguo1230/acg-faka:sha-9927235
python3 docker/build-rollback.py
python3 tests/upgrade/run.py --rollback-image acg-faka:3.5.4-upgrade-rollback
```

2026-09-19：上述完整隔离回归已通过，PHP/Python/Shell 语法及文档命令语法检查通过。两个镜像已推送到 GHCR，远端摘要与本地已测试镜像一致，尚未部署生产。

测试使用独立的内部 Docker 网络、MySQL 8 临时数据库、3.5.4 安装结构及模拟签名网关。不会挂载生产卷、开放主机端口或发送真实通知，结束时清理测试容器及网络。覆盖：

- 只读预检、重复迁移、多配置、停用接口、无效绑定、空默认配置修复、失败后重试、空历史范围不扩张。
- 旧/新商品及充值通知、错签名/金额/订单号/插件/账户、重复及并发通知。
- 后台会话创建和撤销、选中卡密导出、置顶保留凭据。
- 升级镜像实际启动、重启、健康检查、配置保留及新购买/充值到发货入账。
- 回滚镜像在升级前和升级后处理支付、继续下单、再升级和实际容器启动。

仍需在旧站备份副本核对实际支付插件、第三方主题及通知插件；模拟网关通过不能证明真实商户凭据有效。旧回调要求插件声明验签及可直接读取的订单号字段；订单号只在解密后出现的特殊插件需要单独适配。`--check` 不验证 DDL 权限或调用真实网关，这两项分别由副本升级和受控付款验证。

## 镜像传到服务器

本次已发布至 `ghcr.io/wangguo1230/acg-faka`，平台为 `linux/amd64`：

| 用途 | 固定版本标签 | 同内容标签 |
|---|---|---|
| 升级 | `3.7.6-upgrade-2cb51de25b13` | `3.7.6-upgrade-fixed`、`3.7.6`、`latest` |
| 兼容回滚 | `3.5.4-rollback-cbddc781098b` | `3.5.4-upgrade-rollback` |

服务器建议按已核验的完整摘要拉取，避免后续标签变化：

```bash
export ACG_UPGRADE_IMAGE=ghcr.io/wangguo1230/acg-faka@sha256:2cb51de25b1333c63d671078962bb5371937d33c0462cb41bbb5332596453076
export ACG_ROLLBACK_IMAGE=ghcr.io/wangguo1230/acg-faka@sha256:cbddc781098b616f6300fd68c1a39d9b7ad04b98a6d88cb8403f13e278467acb
docker pull "$ACG_UPGRADE_IMAGE"
docker pull "$ACG_ROLLBACK_IMAGE"
```

拉取完成后进入“在线预检”。也可以选择下面的本地镜像传输方式。现有 Git pre-push hook 会构建并发布 `latest`，不要将普通 `git push` 当成只同步代码的步骤。

不通过仓库发布时，在构建机器执行：

```bash
export ACG_EXPORT_DIR="$HOME/acg-upgrade-export"
mkdir -p "$ACG_EXPORT_DIR"
docker image inspect acg-faka:3.7.6-upgrade-fixed acg-faka:3.5.4-upgrade-rollback \
  --format '{{.Id}} {{join .RepoTags ","}}' > "$ACG_EXPORT_DIR/upgrade-images.txt"
docker save acg-faka:3.7.6-upgrade-fixed acg-faka:3.5.4-upgrade-rollback | gzip > "$ACG_EXPORT_DIR/upgrade-images.tar.gz"
```

将这两个文件传到服务器，在原 Compose 项目目录加载并核对镜像 ID：

```bash
gunzip -c /path/to/upgrade-images.tar.gz | docker load
docker image inspect acg-faka:3.7.6-upgrade-fixed acg-faka:3.5.4-upgrade-rollback \
  --format '{{.Id}} {{join .RepoTags ","}}'
export ACG_UPGRADE_IMAGE=acg-faka:3.7.6-upgrade-fixed
export ACG_ROLLBACK_IMAGE=acg-faka:3.5.4-upgrade-rollback
```

如果通过仓库分发，以上变量改为已验收的 `仓库@sha256:...`，提前拉取两个镜像。临时修复标签不应重用于另一次发布。

## 在线预检

旧容器仍运行时执行。以下名称与现有生产 Compose 一致；若已调整，以实际部署为准。

```bash
: "${ACG_UPGRADE_IMAGE:?请先准备升级镜像}"
: "${ACG_ROLLBACK_IMAGE:?请先准备兼容回滚镜像}"
docker inspect acg-faka-app --format '{{.Image}} {{.Config.Image}}'
docker inspect acg-faka-app --format '{{range .Mounts}}{{println .Destination .Name .Source}}{{end}}'

docker run --rm --network 1panel-network \
  --volumes-from acg-faka-app:ro --entrypoint php \
  "$ACG_UPGRADE_IMAGE" /usr/local/bin/acg-faka-migrate.php --check
```

预检返回 0 才进入维护窗口。实际挂载必须仍对应原 `acg_config`、`acg_install`、`acg_runtime`、`acg_assets_cache`、`acg_plugins`、`acg_pay`、`acg_themes` 七个卷；卷名前缀由原 Compose 项目名决定。不要换项目名后误接空卷。

## 停写、备份、切换

在 1Panel 反向代理将站点及相关分站置为维护响应，停止全部应用写入，再停止 app。后台“关闭店铺”只影响首页，无法阻止订单 API，不足以完成停写。维护期间支付通知可能失败；需要网关稍后重试，上线后对账补查。

```bash
docker compose -f docker-compose.prod.yml stop app

# 备份目录必须在网站目录之外；示例目录可按服务器实际调整。
export ACG_BACKUP_DIR="/opt/backups/acg-faka/$(date +%Y%m%d-%H%M%S)"
mkdir -p "$ACG_BACKUP_DIR"
chmod 700 "$ACG_BACKUP_DIR"
docker run --rm --network none --volumes-from acg-faka-app:ro \
  -v "$ACG_BACKUP_DIR:/backup" --entrypoint sh "$ACG_UPGRADE_IMAGE" \
  -c 'umask 077; tar -czpf /backup/volumes.tar.gz -C /var/www/html config kernel/Install runtime assets/cache app/Plugin app/Pay app/View/User/Theme'
tar -tzf "$ACG_BACKUP_DIR/volumes.tar.gz" >/dev/null
```

保持 app 停止，通过 1Panel 对原 MySQL 数据库执行完整备份，核验备份可恢复。数据库含非事务表，不能用在线 `--single-transaction` 代替停止写入后的一致备份。外部 MySQL、Redis继续运行。

将原部署 `.env` 中的 `ACG_IMAGE` 设为本次固定升级镜像，同时保持原项目名、端口、外部网络和数据库配置；下面的环境变量确保本次切换明确使用该镜像：

```bash
ACG_IMAGE="$ACG_UPGRADE_IMAGE" \
  docker compose -f docker-compose.prod.yml up -d --no-deps --pull never app
docker compose -f docker-compose.prod.yml logs --tail=150 app
docker inspect acg-faka-app --format '{{.Image}} {{.State.Status}} {{.State.Health.Status}}'
```

日志必须出现 `[migrate] 完成`，容器必须进入 `healthy`。保持公众维护响应，允许验收人员访问首页、商品、历史订单和后台；重新登录后检查原支付接口。开放通知路由，做一笔受控商品支付及充值，核对发货、手续费、重复通知和通知插件行为，确认后解除维护。

不要求所有旧订单在停服前付完款。停服窗口中的已支付订单须与网关对账，未重试成功的通知使用网关补发功能处理。迁移时间受库大小、ALTER TABLE 和卷同步影响，应先在备份副本实测维护窗口。

## 回滚

升级版开始产生订单后，使用 `acg-faka:3.5.4-upgrade-rollback` 或其已发布的固定 digest。它基于 `9927235`，支持新旧回调地址及新配置绑定；保留当前数据库，继续处理已产生订单的付款。它在升级前的原始旧库上也能运行。

兼容回滚期间禁止修改/删除支付接口和修改商户文件，避免旧管理界面与新配置表产生分歧。回滚后的新购买/充值只支持 CNY，非 CNY 新下单会明确拒绝；已有订单仍按记录的网关金额核验回调。回滚不提供 3.7.6 的其他新增功能，恢复升级版后再修改支付账户、币种或使用这些功能。

```bash
: "${ACG_ROLLBACK_IMAGE:?请准备已验收的兼容回滚镜像}"
# 先恢复维护响应，停止新业务写入。
docker compose -f docker-compose.prod.yml stop app
ACG_IMAGE="$ACG_ROLLBACK_IMAGE" \
  docker compose -f docker-compose.prod.yml up -d --no-deps --pull never app
docker compose -f docker-compose.prod.yml logs --tail=150 app
docker inspect acg-faka-app --format '{{.Image}} {{.State.Status}} {{.State.Health.Status}}'
```

同时将 `.env` 的 `ACG_IMAGE` 固定为回滚镜像，防止后续 `up -d` 回到默认 `latest`。验收通过后恢复业务。

不要整库恢复到升级前而丢掉新订单、余额和已发卡记录；也不要执行 `Install.sql`、删除新增表列或 `docker compose down -v`。兼容回滚通常只需切换镜像。若还需恢复文件，先依据维护窗口备份确认具体文件范围，避免覆盖回滚前新增的业务数据。
