# Bakes the islandora_rag vector core into the Solr image. This avoids a
# read-only bind mount fighting the image's startup `chown -R` of the Solr home,
# and keeps the config owned by the solr user. Only the core's index data is a
# mounted volume (see docker-compose.yaml), placed under the Solr home so the
# startup chown makes it writable.
FROM islandora/solr:6.4.3@sha256:5cb87ecc431acd07000374dd7e83897b10033ecdfa679e1c5f2426957e636127

COPY core.properties /opt/solr/server/solr/islandora_rag/core.properties
COPY conf /opt/solr/server/solr/islandora_rag/conf
