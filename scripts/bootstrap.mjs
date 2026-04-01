import crypto from 'node:crypto'
import fs from 'node:fs'
import path from 'node:path'
import { spawnSync } from 'node:child_process'

const rootDir = process.cwd()

const envPairs = [
  ['.env.example', '.env'],
  ['backend/.env.example', 'backend/.env'],
  ['frontend/.env.example', 'frontend/.env'],
]

for (const [exampleRel, envRel] of envPairs) {
  const examplePath = path.join(rootDir, exampleRel)
  const envPath = path.join(rootDir, envRel)

  if (!fs.existsSync(examplePath)) {
    console.warn(`[bootstrap] skipped missing template: ${exampleRel}`)
    continue
  }

  if (!fs.existsSync(envPath)) {
    fs.copyFileSync(examplePath, envPath)
    console.log(`[bootstrap] created ${envRel}`)
  }
}

const composeEnvPath = path.join(rootDir, '.env')
if (fs.existsSync(composeEnvPath)) {
  let composeEnv = fs.readFileSync(composeEnvPath, 'utf8')
  const appKeyLine = composeEnv.match(/^APP_KEY=.*$/m)

  if (!appKeyLine || appKeyLine[0].trim() === 'APP_KEY=') {
    const generatedKey = `base64:${crypto.randomBytes(32).toString('base64')}`
    if (appKeyLine) {
      composeEnv = composeEnv.replace(/^APP_KEY=.*$/m, `APP_KEY=${generatedKey}`)
    } else {
      composeEnv = `${composeEnv.trim()}\nAPP_KEY=${generatedKey}\n`
    }

    fs.writeFileSync(composeEnvPath, composeEnv, 'utf8')
    console.log('[bootstrap] generated APP_KEY in .env')
  }
}

console.log('[bootstrap] starting docker compose stack')
const result = spawnSync('docker compose up -d --build', {
  cwd: rootDir,
  stdio: 'inherit',
  shell: true,
})

if (result.status !== 0) {
  process.exit(result.status ?? 1)
}

console.log('[bootstrap] stack is up')
