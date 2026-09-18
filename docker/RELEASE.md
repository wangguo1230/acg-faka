# 生产发布约定

生产跑 `ghcr.io/wangguo1230/acg-faka:latest`。`latest` 只是指针，**每次发布必须同时打不可变的版本 tag**，否则回滚只能翻 SHA，容易再次把站打挂。

版本号以 `config/app.php` 的 `version` 为准（当前镜像启动时会覆盖 named volume 里的这份文件）。

## 为什么必须打版本 tag

2026-09-18 把 `main` 的 3.7.6 一次性合进停在 3.5.4 的 `docker-deploy`，并直接刷新了 `latest`。生产立刻出现：

- 下单：`支付配置不存在，请在后台重新为该支付接口选择支付配置`
- 后台：`POST /admin/api/authentication/login` 500

回滚能成功，是因为 GHCR 上还留着 `sha-9927235`，不是发布流程设计好的。当时镜像只有 `latest` / 分支名 / 短 SHA，没有 `3.5.4` 这种人能记的标签。

根因不是 Git 冲突没解干净，而是一次**跨大版本升级**被当成日常合并：

1. `docker-deploy` 是生产线，外部 MySQL 不会随镜像重装。`Install.sql` 只服务全新安装。
2. 3.7.6 把支付改成 `pay_config` 表 + `pay.pay_config_id`，后台登录改成 `manage_session` 表。旧商户号仍在 `app/Pay/*/Config/Config.php` 数据卷里，新代码已经不读。
3. 当时的 `docker/migrate.php` 只加列（`pay_config_id` 默认 0），不建表、不导配置。`0` 对新代码就是「未选择配置」。
4. 修支付的入口在后台，后台登录也依赖新表，两条生命线一起断。
5. `latest` 被覆盖后没有版本 tag，回滚成本高。

代码已经补上：启动时建表、把旧 `Config.php` 导入 `pay_config` 并绑定接口；关键迁移失败则拒绝启动。发布规则必须一起改，否则下次还会只推 `latest`。

## 镜像标签

推 `docker-deploy` 时由 `docker/build-push.sh` 一次打齐：

| 标签 | 含义 | 可变？ |
|---|---|---|
| `latest` | 生产正在跑的指针 | 每次发布移动 |
| `X.Y.Z` | `config/app.php` 的版本，例如 `3.5.4`、`3.7.6` | 同版本重建时覆盖 |
| `X.Y.Z-<sha>` | 该构建的不可变句柄 | 否 |
| `sha-<sha>` | 短提交 | 否 |
| `docker-deploy` | 分支名 | 随分支移动 |

生产 compose 默认拉 `latest`。回滚不要改 compose 里的默认值，临时钉死版本：

```bash
ACG_IMAGE=ghcr.io/wangguo1230/acg-faka:3.5.4 \
  docker compose -f docker-compose.prod.yml pull
ACG_IMAGE=ghcr.io/wangguo1230/acg-faka:3.5.4 \
  docker compose -f docker-compose.prod.yml up -d
```

恢复跟踪最新后再去掉 `ACG_IMAGE`，重新 `pull && up -d`。

## Git 标签

生产发布在 `docker-deploy` 上打 `vX.Y.Z`（与 `config/app.php` 一致）。

- 同一产品版本若只有 Docker 侧修复、应用版本号不变，用 `vX.Y.Z-docker.N`。
- 不要把生产 Git tag 打在 `main` 上：`main` 的 3.7.6 和 `docker-deploy` 的 3.7.6 不是同一个提交。

```bash
git tag -a "v3.7.6" -m "docker production 3.7.6"
git push origin "v3.7.6"
```

`docker/build-push.sh` 在推 `docker-deploy` 镜像时，若本地还没有 `vX.Y.Z`，会自动建 annotated tag，但仍需手动 `git push origin vX.Y.Z`。

## 升级门禁

把 `main` 合进 `docker-deploy` 不是一次 Git merge，是一次**数据库升级**：

1. 对照 `kernel/Install/Install.sql`：新表、新列、需要回填的数据（尤其是支付配置）。
2. 写进 `docker/migrate.php`，关键路径失败必须 `exit 1`，`entrypoint.sh` 不得 `|| true` 吞掉。
3. 不要从 3.5.x 直接跳到 3.7.x；中间小版本的表结构也要能被当前迁移脚本一次补齐。
4. 发布前验证：后台登录、支付方式能列出、能下一单。三步不过，禁止移动 `latest`。
5. 先确保旧版本已有镜像 tag（例如当前生产是 `3.5.4`），再构建新版本并移动 `latest`。

回退到旧镜像时，3.7.x 多出来的列可以留着，3.5.4 代码会忽略它们。**不要 DROP 这些列**。没有建出来的新表（当时的事故现场）本来就不存在，旧代码也不依赖。

## 发布与回滚命令

发布（会移动 `latest`，并打 `X.Y.Z` / `X.Y.Z-<sha>`）：

```bash
git checkout docker-deploy
bash docker/build-push.sh docker-deploy
git push origin docker-deploy
git push origin "v$(sed -nE "s/.*'version'[[:space:]]*=>[[:space:]]*'([^']+)'.*/\1/p" config/app.php | head -n1)"
```

服务器：

```bash
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d
docker inspect acg-faka-app --format '{{index .Config.Labels "org.opencontainers.image.revision"}} {{.Config.Image}}'
```

启动日志必须出现 `[migrate] 完成`。若出现 `[entrypoint] 数据库迁移失败，拒绝启动`，不要强行改回 `|| true`，先修迁移。
