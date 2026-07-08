# Bakes the islandora_rag vector core into the Solr image. This avoids a
# read-only bind mount fighting the image's startup `chown -R` of the Solr home,
# and keeps the config owned by the solr user. Only the core's index data is a
# mounted volume (see docker-compose.yaml), placed under the Solr home so the
# startup chown makes it writable.
FROM islandora/solr:6.4.2@sha256:636df456353546cc0bd14b200d9a5ba7c91a720aa101f52bb80efb63f9a909a6

COPY core.properties /opt/solr/server/solr/islandora_rag/core.properties
COPY conf /opt/solr/server/solr/islandora_rag/conf
