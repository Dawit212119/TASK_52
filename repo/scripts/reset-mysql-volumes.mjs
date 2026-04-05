/**
 * Stops compose and removes MySQL named volumes so the next `up` does a clean init.
 * Use when db logs show: "data directory has files in it" / "unusable" / switching from another DB engine.
 */
import { spawnSync } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..')
const compose = ['compose', '-f', 'docker-compose.yml', '-p', 'vetops']

function run(cmd, args, opts = {}) {
  const r = spawnSync(cmd, args, { stdio: 'inherit', ...opts })
  return r.status ?? 1
}

console.log('[reset-mysql-volumes] stopping stack (compose project vetops)…')
run('docker', [...compose, 'down', '--remove-orphans'], { cwd: root })

const vols = ['vetops_vetops_db_data', 'vetops_vetops_db_test_data']
for (const v of vols) {
  console.log(`[reset-mysql-volumes] removing volume ${v}…`)
  const st = run('docker', ['volume', 'rm', v], { cwd: root })
  if (st !== 0) {
    console.log(`[reset-mysql-volumes] (skip ${v} if it did not exist or was in use)`)
  }
}

console.log('[reset-mysql-volumes] done. Run: npm run up   (or docker compose -p vetops up -d --build)')
