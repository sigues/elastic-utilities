# About the project
PHP web app using [Docker](https://docker.com) and [Lumen Framework](https://lumen.laravel.com/).
Developed to manage ElasticSearch indices and documents and to migrate data from ElasticSearch to Loki.
It includes docker containers for the web app and Loki, Grafana and Promtail

# Quick Start
1. Clone this repo
2. Copy `.env.example` to `.env`
3. Run `docker-compose up -d --force-recreate`

# ElasticSearch 
1. Set your ElasticSearch API Key credentials in the `.env` file

# Loki
1. Set your Loki api url in the `.env` file

# Test the server is running
`curl localhost`

# Credits
1. Naveed Khan [@naveed125](https://github.com/naveed125): For lumen repository.
2. Tim de Pater [@TrafeX](https://github.com/TrafeX): For [Docker PHP Image](https://github.com/TrafeX/docker-php-nginx)
3. Salvador Villegas [@sigues](https://github.com/sigues): For Loki/Promtail/Grafana implementation and the utilities developed in this repo

# Utilities
 - MigrateElasticToLoki, This command migrates all your ElasticSearch data into Loki. To use it:
   - Check `getNextIndex` method and modify to fetch your indices from ElasticSearch accordingly
   - Check `getLabels` method and modify to use your own labels
   - The last sync index and time will be persisted in app/storage/tracking/status.json,
     so if the sync is interrupted the script will start from the date and index specified in the file
 - DeleteElasticIndexes, deletes elastic indices using a wildcard and restores them from a snapshot.
 - DeleteAndRestoreRedIndexes, deletes elastic indices from a list and restores them from a snapshot.
   - We used this to recover our indices when some of them where in red state 
 - FixingSkippedAliases, in case the index doesn't have an alias, this command will detect them and fix them.
 - ReindexIndices, simple script to call the reindex method in ElasticSearch, modify how the indices are built.
 - ValidateAndAssignILMs, script to get the current ILM policy for an index, make sure is the right one and move it to the desired step
 - ValidateElasticIndexes, This script was used to validate the indices migration script worked correctly
 - ValidateReindex, Script to validate the reindex worked correctly

# Services
 - ElasticSearchClient, This class has a few wrapper methods to use the ElasticSearch client
 - LokiClient, Class to push data to Loki
