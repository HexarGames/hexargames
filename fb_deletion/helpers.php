<?php

function fb_apps_config(): array {
  static $apps = null;
  if ($apps !== null) return $apps;

  $path = __DIR__ . '/apps.php';
  $loaded = [];
  if (is_file($path)) {
    $loaded = require $path;
    if (!is_array($loaded)) $loaded = [];
  }

  return $apps = $loaded;
}

function fb_slugify(string $value): string {
  $slug = preg_replace('/[^a-z0-9-]+/i', '-', $value);
  $slug = strtolower(trim((string)$slug, '-'));
  return $slug;
}

function fb_app_slug(string $appId): string {
  $config = fb_apps_config();
  $settings = $config[$appId] ?? null;
  if (is_array($settings) && isset($settings['slug'])) {
    $candidate = fb_slugify((string)$settings['slug']);
    if ($candidate !== '') return $candidate;
  }
  return fb_slugify((string)$appId) ?: (string)$appId;
}

function fb_app_id_from_slug(?string $slug): ?string {
  if ($slug === null) return null;
  $needle = fb_slugify($slug);
  if ($needle === '') return null;
  foreach (fb_apps_config() as $appId => $settings) {
    if (is_array($settings) && isset($settings['slug'])) {
      $candidate = fb_slugify((string)$settings['slug']);
      if ($candidate !== '' && $candidate === $needle) return (string)$appId;
    }
    $fallback = fb_slugify((string)$appId);
    if ($fallback !== '' && $fallback === $needle) return (string)$appId;
  }
  return null;
}
