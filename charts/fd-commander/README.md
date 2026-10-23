# Field Day Commander Helm Chart

Deploys [Field Day Commander](https://github.com/ckoval7/fielddaycommander), an
amateur radio Field Day logging and scoring application, into Kubernetes.

The chart also ships a `questions.yaml`, so it appears as a form-driven
application in the Rancher Apps catalog.

## Installing

```bash
helm install fd-commander charts/fd-commander \
  --namespace fd-commander --create-namespace \
  --set app.url=https://fd.example.com \
  --set ingress.enabled=true \
  --set ingress.host=fd.example.com
```

The first boot waits for the database, then runs migrations and the production
seeders before Octane starts serving, so allow a few minutes before the app
answers. The startup probe is sized for this.

Then open the app. Until setup is done, every page redirects to the setup
wizard, which sets the password for the `SYSTEM` administrator account and the
site name and preferences. Afterwards, sign in as callsign `SYSTEM` with the
password you chose. Finish the wizard right away, because whoever reaches the
site first can complete it.

## What gets deployed

A single application pod runs FrankenPHP/Octane under supervisord, alongside the
Reverb WebSocket server, a queue worker and the scheduler. MySQL and Redis run
as optional single-replica StatefulSets built into this chart, using the
official `mysql` and `redis` images at the same pinned versions as
`docker-compose.yml` (MySQL 8.4.11, Redis 7.4.11). Both run as their image's
non-root user (uid 999) with a persistent volume each. Turn either off to point
at an existing server through `externalDatabase` or `externalRedis`.

## Configuration

| Key | Description | Default |
| --- | --- | --- |
| `app.url` | Public URL, no trailing slash. Asset and storage links derive from it. | `http://localhost` |
| `app.key` | Laravel encryption key. Generated on first install when blank. | `""` |
| `image.repository` | Container image. | `ghcr.io/ckoval7/fielddaycommander` |
| `image.tag` | Image tag. Defaults to the chart's `appVersion`. | `""` |
| `app.trustedProxies` | Proxies whose forwarded client IP and scheme are believed. | private ranges |
| `ingress.enabled` | Expose through an ingress controller. | `false` |
| `ingress.maxBodySize` | Largest request body ingress-nginx accepts (photo uploads go up to 25 MB). Blank keeps the controller's limit. | `35m` |
| `service.type` | Service type for the web interface. | `ClusterIP` |
| `persistence.size` | Volume for uploads, gallery images and logs. | `10Gi` |
| `mysql.enabled` | Deploy the bundled MySQL StatefulSet. | `true` |
| `mysql.primary.persistence.size` | MySQL data volume size. | `8Gi` |
| `mysql.primary.persistence.existingClaim` | Use an existing claim for MySQL data (accepts templates). | `""` |
| `mysql.primary.persistence.subPath` | Sub-directory of the volume used as the data directory. | `""` |
| `mysql.volumePermissions.enabled` | Root init container that chowns the data directory to uid 999. | `false` |
| `redis.enabled` | Deploy the bundled Redis StatefulSet. | `true` |
| `redis.master.persistence.size` | Redis data volume size. | `2Gi` |
| `redis.master.configuration` | `redis.conf` contents (AOF, eviction, `maxmemory`). | see `values.yaml` |
| `reverb.enabled` | Run the Reverb WebSocket server for live updates. | `true` |
| `externalLoggers.enabled` | Expose the UDP ports for radio logging software. | `true` |

### Persistence

`storage/` holds uploads, gallery images, logs and the marker file recording
that the seeders have run. Its claim is annotated `helm.sh/resource-policy: keep`
so uninstalling the release does not delete logged contacts' attachments.

### Uninstalling

`helm uninstall` keeps everything that holds data or the keys to it:

- the claims `<release>-fd-commander-storage`, `mysql-data-<release>-mysql-0`
  and `redis-data-<release>-redis-0`
- the secrets `<release>-fd-commander` (`APP_KEY`, Reverb credentials),
  `<release>-mysql` and `<release>-redis`

Installing again under the same release name and namespace picks all of them
up, so the database opens with its old password and the `APP_KEY` still
decrypts two-factor secrets and other encrypted data.

To remove everything for good, delete them after uninstalling:

```bash
kubectl -n <namespace> delete pvc <release>-fd-commander-storage \
  mysql-data-<release>-mysql-0 redis-data-<release>-redis-0
kubectl -n <namespace> delete secret <release>-fd-commander <release>-mysql <release>-redis
```

Delete the claims and secrets together. A new install that finds the old MySQL
claim but not its secret generates a new password the old database won't
accept.

### Credentials

The bundled MySQL and Redis passwords are generated on first install (or taken
from `mysql.auth.password`, `mysql.auth.rootPassword` and `redis.auth.password`)
into the `<release>-mysql` and `<release>-redis` secrets. The database pods and
the application read the same secret, and upgrades reuse the stored values, so
nothing can drift between the two. The `APP_KEY` and Reverb credentials are
generated once and preserved across upgrades — changing `APP_KEY` later makes
existing encrypted data and sessions unreadable.

Changing a password with `helm upgrade` restarts the pods that read it: the
application, and Redis, which picks up the new password on start. MySQL is
different. It only applies `mysql.auth.password` and `mysql.auth.rootPassword`
when it creates an empty database, so the chart doesn't restart it, and a new
value alone locks the app out. Change the password inside MySQL first, then
upgrade with the same value:

```bash
kubectl -n <namespace> exec -it <release>-mysql-0 -- sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"'
mysql> ALTER USER 'fd_commander'@'%' IDENTIFIED BY '<new>';
mysql> exit
helm upgrade <release> charts/fd-commander -n <namespace> --reset-then-reuse-values \
  --set mysql.auth.password='<new>'
```

To supply everything yourself, put `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`,
`MAIL_PASSWORD`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` and `REVERB_APP_ID` into
one secret and set `existingSecret`.

### Upgrading from chart 0.x (Bitnami subcharts)

Chart 0.x pulled MySQL and Redis from Bitnami subcharts, whose images Bitnami
stopped publishing in 2025. Chart 1.0 replaces them with its own StatefulSets.
The secrets keep their names and keys, so the passwords carry over, but the
StatefulSets change shape and the MySQL data sits in a different layout, so
the upgrade needs a few manual steps.

Two mistakes cause most failed upgrades:

- **"spec: Forbidden" error.** Helm can't convert the old StatefulSets in
  place, so you have to delete them first (step 2). If you forgot, the
  upgrade fails partway and the app goes down, but no data is lost. Helm has
  already applied everything before the MySQL StatefulSet: the app runs the
  new image, a new `<release>-redis` StatefulSet has started, and the
  `<release>-mysql` Service no longer matches the old MySQL pod, so the app
  can't reach its database. The old MySQL pod and its claim are untouched.
  Carry on with steps 2 and 3 and the app comes back.
- **`--reuse-values`.** This flag replaces the new chart's defaults with every
  value from the old release, including the Bitnami subchart's images, uid
  1001 and read-only root filesystem. Use `--reset-then-reuse-values`
  (Helm 3.14 or newer) instead, which starts from the new defaults and keeps
  only the values you set yourself. On an older Helm, pass your own values
  file with `-f` and leave both flags off.

The Bitnami chart kept MySQL data on the claim `data-<release>-mysql-0`, with
the data directory in its `data/` sub-directory, owned by uid 1001. Chart 1.0
can mount that claim directly:

1. Take a backup first. The repo's `backup.sh` targets Docker and native
   installs, so dump from the pod instead:

   ```bash
   kubectl -n <namespace> exec <release>-mysql-0 -- sh -c \
     'MYSQL_PWD="$(cat "$MYSQL_ROOT_PASSWORD_FILE" 2>/dev/null || echo "$MYSQL_ROOT_PASSWORD")" \
      mysqldump -uroot --single-transaction --routines fd_commander' > fd_commander.sql
   ```
2. Remove the Bitnami StatefulSets. Their claims are kept:

   ```bash
   kubectl -n <namespace> delete statefulset <release>-mysql <release>-redis-master
   ```
3. Upgrade, pointing MySQL at the old claim:

   ```bash
   helm upgrade <release> charts/fd-commander -n <namespace> --reset-then-reuse-values \
     --set-string 'mysql.primary.persistence.existingClaim=data-{{ .Release.Name }}-mysql-0' \
     --set mysql.primary.persistence.subPath=data \
     --set mysql.volumePermissions.enabled=true
   ```

   If you pass your own values file instead, remove any `bitnamilegacy`
   image, `global.security` or Bitnami-style `podSecurityContext` and
   `containerSecurityContext` overrides from it. (The chart ignores Bitnami
   images and security contexts it recognises and uses its own defaults, but
   a clean file is easier to reason about.) `volumePermissions` runs a root
   init container that hands the files from uid 1001 to the official image's
   uid 999. It only changes files not already owned by 999, so you can turn
   it off after the first successful start. MySQL 8.4.11 upgrades the 8.4.x
   data dictionary in place on first start.

   In Rancher, delete the StatefulSets (step 2) before you click **Upgrade**.
   The upgrade form starts from the release's current answers, so open
   **Edit YAML**, remove any Bitnami image or `global.security` overrides,
   and add the three `mysql` settings from the command above.

Redis starts fresh on a new claim (`redis-data-<release>-redis-0`). It only
holds cache, sessions and the job queue, so users are signed out and any queued
jobs not yet run are dropped. Delete the old `redis-data-<release>-redis-master-0`
claim once you are happy.

If you would rather start MySQL on a clean volume, leave `existingClaim` unset,
then restore the dump into the new pod. The old `data-<release>-mysql-0` claim
is not deleted, so delete it by hand once the restore checks out.

### External loggers

N1MM Logger+, WSJT-X and fldigi send contacts to the application over UDP. Those
listeners run inside the application pod, so the ports are exposed through a
separate `-udp` service. A `ClusterIP` will not work here — the logging software
sits on the LAN, outside the cluster:

- **LoadBalancer** keeps the standard port numbers (12060, 2237, 2238), which is
  what the logging software expects. Prefer this.
- **NodePort** remaps them into the node port range, so the logging software has
  to be pointed at the remapped port instead.

The port numbers configured here must match those set in the admin UI under
*Settings > External Loggers*.

The NodePort defaults are fixed (31060, 32237, 32238), so the port you type
into the logging software stays the same across reinstalls. Node ports are
shared by the whole cluster, so a second release (a test install next to the
real one, say) fails with "provided port is already allocated". Give it other
numbers under `externalLoggers.service.nodePorts`, or set them to `""` to let
Kubernetes pick free ports, which `kubectl get svc <release>-fd-commander-udp`
then shows.

### Real-time updates

Reverb backs the live scoreboard and dashboard. Browsers connect to `/app`, which
the ingress routes to the Reverb port ahead of the catch-all rule. When running
behind TLS, set `app.url` to the `https://` URL so the WebSocket connection is
negotiated as `wss://`.

The app only answers requests for the `app.url` host, its subdomains and
local-network addresses (private IPs and `.local` names). If people also reach
it by another name, such as a public domain, list it in `app.trustedHosts`.
Live updates work from all of those hosts.

The app believes the client IP and HTTPS headers (`X-Forwarded-For`,
`X-Forwarded-Proto`) only from `app.trustedProxies`. The ingress controller
forwards from a pod or node address, so the default trusts the private ranges
clusters use (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `fc00::/7` and
loopback). If untrusted devices can reach the application's pods directly,
narrow it to the ingress controller's pod CIDR: anyone trusted can fake their
address and get around the login and station-mode rate limits. `reverb.allowedOrigins` adds more
WebSocket origins, and you'll rarely need it.

### Scaling

`replicaCount` above 1 is not supported as shipped. The UDP listeners bind ports
inside a single pod and track their PIDs in the database, the storage volume is
ReadWriteOnce, and the deployment uses the `Recreate` strategy because the
entrypoint runs migrations on start.
