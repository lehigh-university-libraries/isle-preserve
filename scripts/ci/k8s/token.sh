#!/usr/bin/env bash

set -eou pipefail

# if a token wasn't set on the environment
# generate a new one
if [ ! -v TOKEN ]; then
    current_time=$(date +%s)
    file_modification_time=$(stat -c "%Y" "$KUBECONFIG")
    file_age=$((current_time - file_modification_time))
    if [[ $file_age -lt 36000 ]]; then
        echo "Not rotating token, less than 10h old: $file_age"
        exit 0
    fi

    TOKEN=$(kubectl create token $KUBE_SVC_ACCOUNT \
    --namespace=$KUBE_NAMESPACE \
    --duration=24h)
fi

cat <<EOF > "$KUBECONFIG"
apiVersion: v1
kind: Config
clusters:
- name: kubernetes
  cluster:
    certificate-authority-data: $(cat "$KUBE_CA_CERT_FILE")
    server: $KUBE_SERVER_URL
contexts:
- name: ci-context
  context:
    cluster: kubernetes
    namespace: $KUBE_NAMESPACE
    user: $KUBE_SVC_ACCOUNT
current-context: ci-context
users:
- name: $KUBE_SVC_ACCOUNT
  user:
    token: $TOKEN
EOF
