#!/usr/bin/env bash
# 本地构建并推送 acg-faka 生产镜像到 GHCR。
#
# 背景：GitHub Actions 远程构建常滞后（GHCR latest 落后于 docker-deploy），
# 导致新功能在生产失效。改为本地原生构建（本机 amd64 与生产同架构），推送前直接刷新镜像。
#
# 前置条件：已执行 `docker login ghcr.io`（需具备 write:packages 权限的 PAT）。
# 单独手动使用：bash docker/build-push.sh [分支名]
set -euo pipefail

REGISTRY="ghcr.io"
IMAGE="${REGISTRY}/wangguo1230/acg-faka"
PLATFORM="linux/amd64"

cd "$(git rev-parse --show-toplevel)"

# 分支：优先取参数（钩子传入被推送的分支），否则取当前 HEAD 分支
BRANCH="${1:-$(git rev-parse --abbrev-ref HEAD)}"
SHA="$(git rev-parse --short HEAD)"

# 标签：短 sha + 分支名；docker-deploy / main 额外打 latest（对齐原 CI 语义）
TAGS=( "sha-${SHA}" "${BRANCH}" )
case "$BRANCH" in
  docker-deploy|main) TAGS+=( "latest" ) ;;
esac

TAG_ARGS=()
for t in "${TAGS[@]}"; do TAG_ARGS+=( -t "${IMAGE}:${t}" ); done

echo "==> 构建镜像 ${IMAGE}  (branch=${BRANCH} sha=${SHA} platform=${PLATFORM})"
printf '    标签:'; for t in "${TAGS[@]}"; do printf ' %s' "$t"; done; echo

docker build --platform "$PLATFORM" -f Dockerfile "${TAG_ARGS[@]}" .

echo "==> 推送到 ${REGISTRY}"
for t in "${TAGS[@]}"; do
  echo "    push ${IMAGE}:${t}"
  docker push "${IMAGE}:${t}"
done

echo "==> 完成：生产可执行 docker compose -f docker-compose.prod.yml pull && up -d"
