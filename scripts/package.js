#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const os = require('os');
const { spawnSync } = require('child_process');

const rootDir = path.resolve(__dirname, '..');
const packageJsonPath = path.join(rootDir, 'package.json');
const packageJson = JSON.parse(fs.readFileSync(packageJsonPath, 'utf8'));

const pluginSlug = packageJson.name || path.basename(rootDir);
const version = packageJson.version || '0.0.0';
const distDir = path.join(rootDir, 'dist');
const zipFileName = `${pluginSlug}-${version}.zip`;
const zipFilePath = path.join(distDir, zipFileName);

const ignoredNames = new Set([
  '.git',
  '.github',
  '.cursor',
  '.gitignore',
  'node_modules',
  'dist',
  'scripts',
  'src',
  'research',
  'wporg-assets',
  'package.json',
  'package-lock.json',
  'webpack.config.js',
  'phpcs.xml',
  'README.md',
]);

const ignoredExtensions = new Set([
  '.log',
]);

function shouldIgnore(relativePath) {
  const normalizedPath = relativePath.split(path.sep).join('/');
  const parts = normalizedPath.split('/');

  if (parts.some((part) => ignoredNames.has(part))) {
    return true;
  }

  return ignoredExtensions.has(path.extname(relativePath));
}

function copyRecursive(sourceDir, targetDir, currentRelativePath = '') {
  const entries = fs.readdirSync(sourceDir, { withFileTypes: true });

  for (const entry of entries) {
    const entryRelativePath = currentRelativePath
      ? path.join(currentRelativePath, entry.name)
      : entry.name;

    if (shouldIgnore(entryRelativePath)) {
      continue;
    }

    const sourcePath = path.join(sourceDir, entry.name);
    const targetPath = path.join(targetDir, entry.name);

    if (entry.isDirectory()) {
      fs.mkdirSync(targetPath, { recursive: true });
      copyRecursive(sourcePath, targetPath, entryRelativePath);
      continue;
    }

    fs.copyFileSync(sourcePath, targetPath);
  }
}

fs.mkdirSync(distDir, { recursive: true });

const tempRoot = fs.mkdtempSync(path.join(os.tmpdir(), `${pluginSlug}-package-`));
const stagedPluginDir = path.join(tempRoot, pluginSlug);

fs.mkdirSync(stagedPluginDir, { recursive: true });
copyRecursive(rootDir, stagedPluginDir);

if (fs.existsSync(zipFilePath)) {
  fs.rmSync(zipFilePath, { force: true });
}

const zipResult = spawnSync(
  'zip',
  ['-rq', zipFilePath, pluginSlug],
  {
    cwd: tempRoot,
    stdio: 'inherit',
  }
);

fs.rmSync(tempRoot, { recursive: true, force: true });

if (zipResult.status !== 0) {
  process.exit(zipResult.status || 1);
}

console.log(`Created ${path.relative(rootDir, zipFilePath)}`);
