#!/usr/bin/env bash
# 本地构建并推送 acg-faka 生产镜像到 GHCR。
#
# 生产默认跑 latest，但每次发布必须同时打 config/app.php 的版本号
#（X.Y.Z 与 X.Y.Z-<sha>）。只推 latest 会导致回滚只能翻短 SHA。
# 约定见 docker/RELEASE.md。
#
# 前置条件：已执行 `docker login ghcr.io`（需具备 write:packages 权限的 PAT）。
# 单独手动使用：bash docker/build-push.sh [分支名]
set -euo pipefail

REGISTRY="ghcr.io"
IMAGE="${REGISTRY}/wangguo1230/acg-faka"
PLATFORM="linux/amd64"

cd "$(git rev-parse --show-toplevel)"

BRANCH="${1:-$(git rev-parse --abbrev-ref HEAD)}"
SHA="$(git rev-parse --short HEAD)"

VERSION="$(sed -nE "s/.*'version'[[:space:]]*=>[[:space:]]*'([^']+)'.*/\1/p" config/app.php | head -n1)"
if [[ ! "${VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][A-Za-z0-9.]+)?$ ]]; then
  echo "!!  无法从 config/app.php 解析版本号（得到：${VERSION:-<empty>}）" >&2
  exit 1
fi

# 版本号 + 不可变句柄必须始终存在。latest 只在生产线 / 默认分支上移动。
TAGS=( "sha-${SHA}" "${VERSION}" "${VERSION}-${SHA}" "${BRANCH}" )
case "$BRANCH" in
  docker-deploy|main) TAGS+=( "latest" ) ;;
esac

TAG_ARGS=()
for t in "${TAGS[@]}"; do TAG_ARGS+=( -t "${IMAGE}:${t}" ); done

echo "==> 构建镜像 ${IMAGE}  (version=${VERSION} branch=${BRANCH} sha=${SHA} platform=${PLATFORM})"
printf '    标签:'; for t in "${TAGS[@]}"; do printf ' %s' "$t"; done; echo

docker build --platform "$PLATFORM" -f Dockerfile "${TAG_ARGS[@]}" \
  --label "org.opencontainers.image.version=${VERSION}" \
  --label "org.opencontainers.image.revision=${SHA}" \
  --label "org.opencontainers.image.source=https://github.com/wangguo1230/acg-faka" \
  .

echo "==> 推送到 ${REGISTRY}"
for t in "${TAGS[@]}"; do
  echo "    push ${IMAGE}:${t}"
  docker push "${IMAGE}:${t}"
done

GIT_TAG="v${VERSION}"
if [ "${BRANCH}" = "docker-deploy" ]; then
  if git rev-parse "${GIT_TAG}" >/dev/null 2>&1; then
    echo "==> Git 标签 ${GIT_TAG} 已存在：$(git rev-parse --short "${GIT_TAG}")"
  else
    git tag -a "${GIT_TAG}" -m "docker production ${VERSION} (${SHA})"
    echo "==> 已创建 Git 标签 ${GIT_TAG}，请执行：git push origin ${GIT_TAG}"
  fi
fi

echo "==> 完成：生产默认仍用 latest；回滚请钉 ACG_IMAGE=${IMAGE}:${VERSION}"
echo "    docker compose -f docker-compose.prod.yml pull && docker compose -f docker-compose.prod.yml up -d"
