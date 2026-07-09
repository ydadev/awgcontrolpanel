-- amneziawg-go now requires Go 1.25+, while the upstream Dockerfile may still
-- resolve to an older golang image. Patch the local build context before build.

UPDATE protocols
SET install_script = REPLACE(
  install_script,
  'docker build --no-cache -t amnezia-awg2 /opt/amnezia/awg2/src',
  'if [ -d /opt/amnezia/awg2/src/.git ]; then
  git -C /opt/amnezia/awg2/src pull --ff-only >/dev/null 2>&1 || true
fi
if [ -f /opt/amnezia/awg2/src/Dockerfile ]; then
  sed -i -E "s#^FROM[[:space:]]+golang(:[^[:space:]]*)?[[:space:]]+[aA][sS][[:space:]]+awg#FROM golang:1.25-alpine AS awg#" /opt/amnezia/awg2/src/Dockerfile
fi
docker build --no-cache -t amnezia-awg2 /opt/amnezia/awg2/src'
)
WHERE slug = 'awg2';
