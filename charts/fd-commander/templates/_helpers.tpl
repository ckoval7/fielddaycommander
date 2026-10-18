{{/*
SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
SPDX-License-Identifier: GPL-3.0-or-later
*/}}

{{/*
Expand the name of the chart.
*/}}
{{- define "fd-commander.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Fully qualified app name.
*/}}
{{- define "fd-commander.fullname" -}}
{{- if .Values.fullnameOverride }}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- $name := default .Chart.Name .Values.nameOverride }}
{{- if contains $name .Release.Name }}
{{- .Release.Name | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- printf "%s-%s" .Release.Name $name | trunc 63 | trimSuffix "-" }}
{{- end }}
{{- end }}
{{- end }}

{{- define "fd-commander.chart" -}}
{{- printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" | trunc 63 | trimSuffix "-" }}
{{- end }}

{{- define "fd-commander.labels" -}}
helm.sh/chart: {{ include "fd-commander.chart" . }}
{{ include "fd-commander.selectorLabels" . }}
{{- if .Chart.AppVersion }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
{{- end }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end }}

{{- define "fd-commander.selectorLabels" -}}
app.kubernetes.io/name: {{ include "fd-commander.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end }}

{{- define "fd-commander.serviceAccountName" -}}
{{- if .Values.serviceAccount.create }}
{{- default (include "fd-commander.fullname" .) .Values.serviceAccount.name }}
{{- else }}
{{- default "default" .Values.serviceAccount.name }}
{{- end }}
{{- end }}

{{/*
Name of the secret holding sensitive values.
*/}}
{{- define "fd-commander.secretName" -}}
{{- if .Values.existingSecret }}
{{- .Values.existingSecret }}
{{- else }}
{{- include "fd-commander.fullname" . }}
{{- end }}
{{- end }}

{{/*
Names of the bundled MySQL and Redis resources (StatefulSet, Service, Secret).
They keep the <release>-mysql / <release>-redis names the Bitnami subcharts
used, so the generated passwords carry over on upgrade from chart 0.x.
*/}}
{{- define "fd-commander.mysql.fullname" -}}
{{- printf "%s-mysql" .Release.Name | trunc 63 | trimSuffix "-" }}
{{- end }}

{{- define "fd-commander.redis.fullname" -}}
{{- printf "%s-redis" .Release.Name | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Labels for the bundled MySQL and Redis pods. app.kubernetes.io/name differs
from the application's, so the app Service never selects database pods.
Call with (dict "component" "mysql" "context" $).
*/}}
{{- define "fd-commander.backingSelectorLabels" -}}
app.kubernetes.io/name: {{ .component }}
app.kubernetes.io/instance: {{ .context.Release.Name }}
app.kubernetes.io/part-of: {{ include "fd-commander.name" .context }}
{{- end }}

{{- define "fd-commander.backingLabels" -}}
helm.sh/chart: {{ include "fd-commander.chart" .context }}
{{ include "fd-commander.backingSelectorLabels" . }}
app.kubernetes.io/managed-by: {{ .context.Release.Service }}
{{- end }}

{{/*
Database host: the bundled MySQL service, or the external host.
*/}}
{{- define "fd-commander.databaseHost" -}}
{{- if .Values.mysql.enabled }}
{{- include "fd-commander.mysql.fullname" . }}
{{- else }}
{{- required "externalDatabase.host is required when mysql.enabled is false" .Values.externalDatabase.host }}
{{- end }}
{{- end }}

{{- define "fd-commander.databasePort" -}}
{{- if .Values.mysql.enabled }}3306{{- else }}{{ .Values.externalDatabase.port }}{{- end }}
{{- end }}

{{- define "fd-commander.databaseName" -}}
{{- if .Values.mysql.enabled }}{{ .Values.mysql.auth.database }}{{- else }}{{ .Values.externalDatabase.database }}{{- end }}
{{- end }}

{{- define "fd-commander.databaseUser" -}}
{{- if .Values.mysql.enabled }}{{ .Values.mysql.auth.username }}{{- else }}{{ .Values.externalDatabase.username }}{{- end }}
{{- end }}

{{/*
Redis host: the bundled Redis service, or the external host.
*/}}
{{- define "fd-commander.redisHost" -}}
{{- if .Values.redis.enabled }}
{{- include "fd-commander.redis.fullname" . }}
{{- else }}
{{- .Values.externalRedis.host }}
{{- end }}
{{- end }}

{{- define "fd-commander.redisPort" -}}
{{- if .Values.redis.enabled }}6379{{- else }}{{ .Values.externalRedis.port }}{{- end }}
{{- end }}

{{/*
True when Redis is available for cache/session/queue drivers. Without it the
app falls back to the database, which works but is slower.
*/}}
{{- define "fd-commander.redisAvailable" -}}
{{- if or .Values.redis.enabled .Values.externalRedis.host }}true{{- end }}
{{- end }}

{{/*
Hostname browsers use to reach Reverb, derived from app.url when not set.
*/}}
{{- define "fd-commander.reverbHost" -}}
{{- if .Values.reverb.host }}
{{- .Values.reverb.host }}
{{- else if .Values.ingress.enabled }}
{{- .Values.ingress.host }}
{{- else }}
{{- .Values.app.url | trimPrefix "https://" | trimPrefix "http://" | splitList ":" | first }}
{{- end }}
{{- end }}

{{/*
"true" when browsers reach the app over HTTPS as far as the chart can tell: an
enabled ingress with TLS, or an https:// app.url. The Reverb scheme and the
secure session cookie both follow it, so the two always agree. (TLS settings
on a disabled ingress do nothing, so they do not count.)
*/}}
{{- define "fd-commander.publicHttps" -}}
{{- if or (and .Values.ingress.enabled .Values.ingress.tls.enabled) (hasPrefix "https://" .Values.app.url) }}true{{- end }}
{{- end }}

{{/*
Public scheme/port browsers use for the WebSocket connection.
*/}}
{{- define "fd-commander.reverbScheme" -}}
{{- if .Values.reverb.scheme }}
{{- .Values.reverb.scheme }}
{{- else if include "fd-commander.publicHttps" . }}
{{- "https" }}
{{- else }}
{{- "http" }}
{{- end }}
{{- end }}

{{- define "fd-commander.reverbPublicPort" -}}
{{- if .Values.reverb.publicPort }}
{{- .Values.reverb.publicPort }}
{{- else if eq (include "fd-commander.reverbScheme" .) "https" }}443{{- else }}80{{- end }}
{{- end }}

{{/*
Image reference.
*/}}
{{- define "fd-commander.image" -}}
{{- printf "%s:%s" .Values.image.repository (default .Chart.AppVersion .Values.image.tag) }}
{{- end }}

{{/*
Secret and key holding the database password. With the bundled MySQL the
credential lives in the <release>-mysql secret, which both the MySQL pod and
the app read, so the two can never drift. With existingSecret, both read it
from there instead.
*/}}
{{- define "fd-commander.databaseSecretName" -}}
{{- if .Values.existingSecret }}
{{- .Values.existingSecret }}
{{- else if .Values.mysql.enabled }}
{{- include "fd-commander.mysql.fullname" . }}
{{- else }}
{{- include "fd-commander.fullname" . }}
{{- end }}
{{- end }}

{{- define "fd-commander.databaseSecretKey" -}}
{{- if .Values.existingSecret }}
{{- .Values.externalDatabase.existingSecretPasswordKey }}
{{- else if .Values.mysql.enabled }}
{{- "mysql-password" }}
{{- else }}
{{- "DB_PASSWORD" }}
{{- end }}
{{- end }}

{{/*
Secret and key holding the Redis password, following the same rule.
*/}}
{{- define "fd-commander.redisSecretName" -}}
{{- if .Values.existingSecret }}
{{- .Values.existingSecret }}
{{- else if .Values.redis.enabled }}
{{- include "fd-commander.redis.fullname" . }}
{{- else }}
{{- include "fd-commander.fullname" . }}
{{- end }}
{{- end }}

{{- define "fd-commander.redisSecretKey" -}}
{{- if and (not .Values.existingSecret) .Values.redis.enabled }}
{{- "redis-password" }}
{{- else }}
{{- "REDIS_PASSWORD" }}
{{- end }}
{{- end }}

{{/*
Whether a Redis password is in play at all.
*/}}
{{- define "fd-commander.redisAuthEnabled" -}}
{{- if .Values.redis.enabled }}
{{- if dig "auth" "enabled" true .Values.redis }}true{{- end }}
{{- else if and .Values.externalRedis.host .Values.externalRedis.password }}
{{- "true" }}
{{- end }}
{{- end }}

{{/*
Every credential the chart generates or carries over, resolved once per render
and cached in .Values._fdcCredentials, so each Secret and the checksum
annotations on the pods that read it see the same values. Call it with include
(it prints nothing), then read .Values._fdcCredentials.

Each value comes from, in order: the value set in values.yaml, the Secret
already in the cluster, or a new random value. The Secret may be left from this
release's last upgrade, from an uninstall (the Secrets are kept, like the data
volumes, so a reinstall under the same name still opens the old database), or
from the chart 0.x Bitnami subcharts, which used the same names and keys.
*/}}
{{- define "fd-commander.credentials" -}}
{{- if not (hasKey .Values "_fdcCredentials") }}
{{- $app := (lookup "v1" "Secret" .Release.Namespace (include "fd-commander.fullname" .)) | default dict }}
{{- $appData := get $app "data" | default dict }}
{{- $mysql := (lookup "v1" "Secret" .Release.Namespace (include "fd-commander.mysql.fullname" .)) | default dict }}
{{- $mysqlData := get $mysql "data" | default dict }}
{{- $redis := (lookup "v1" "Secret" .Release.Namespace (include "fd-commander.redis.fullname" .)) | default dict }}
{{- $redisData := get $redis "data" | default dict }}
{{- $credentials := dict
  "appKey" (.Values.app.key | default (get $appData "APP_KEY" | b64dec) | default (printf "base64:%s" (randAlphaNum 32 | b64enc)))
  "reverbKey" ((get $appData "REVERB_APP_KEY" | b64dec) | default (randAlphaNum 20))
  "reverbSecret" ((get $appData "REVERB_APP_SECRET" | b64dec) | default (randAlphaNum 20))
  "reverbId" ((get $appData "REVERB_APP_ID" | b64dec) | default (randNumeric 8))
  "mysqlPassword" (dig "auth" "password" "" .Values.mysql | default (get $mysqlData "mysql-password" | b64dec) | default (randAlphaNum 24))
  "mysqlRootPassword" (dig "auth" "rootPassword" "" .Values.mysql | default (get $mysqlData "mysql-root-password" | b64dec) | default (randAlphaNum 24))
  "redisPassword" (dig "auth" "password" "" .Values.redis | default (get $redisData "redis-password" | b64dec) | default (randAlphaNum 24))
}}
{{- $_ := set .Values "_fdcCredentials" $credentials }}
{{- end }}
{{- end }}

{{/*
Contents of existingSecret, for the checksum annotations below, so a changed
password in it rolls the pods on the next helm upgrade.
*/}}
{{- define "fd-commander.existingSecretData" -}}
{{- if .Values.existingSecret }}
{{- $existing := (lookup "v1" "Secret" .Release.Namespace .Values.existingSecret) | default dict }}
{{- get $existing "data" | default dict | toJson }}
{{- end }}
{{- end }}

{{/*
Checksum of every credential the application pod reads, so changing one rolls
the pod onto it. The bundled MySQL pod deliberately has no such checksum: MySQL
only reads its passwords when it initialises an empty data directory, so
restarting it would change nothing in the database.
*/}}
{{- define "fd-commander.appCredentialsChecksum" -}}
{{- include "fd-commander.credentials" . }}
{{- $credentials := .Values._fdcCredentials }}
{{- $used := dict "existingSecret" (include "fd-commander.existingSecretData" .) "mail" .Values.mail.password }}
{{- if not .Values.existingSecret }}
{{- $_ := set $used "app" (pick $credentials "appKey" "reverbKey" "reverbSecret" "reverbId") }}
{{- if .Values.mysql.enabled }}
{{- $_ := set $used "database" $credentials.mysqlPassword }}
{{- else }}
{{- $_ := set $used "database" .Values.externalDatabase.password }}
{{- end }}
{{- if and .Values.redis.enabled (include "fd-commander.redisAuthEnabled" .) }}
{{- $_ := set $used "redis" $credentials.redisPassword }}
{{- else }}
{{- $_ := set $used "redis" .Values.externalRedis.password }}
{{- end }}
{{- end }}
{{- toJson $used | sha256sum }}
{{- end }}
