<?php
// Fetches paths as uid 1: status, dynamic cache, add-vehicle link.
use Drupal\user\Entity\User;
use GuzzleHttp\Cookie\CookieJar;
$base = 'https://drupal-civi-composer-exp-d11.ddev.site';
$client = \Drupal::httpClient();
$jar = new CookieJar();
$client->get($base . parse_url(user_pass_reset_url(User::load(1)) . '/login', PHP_URL_PATH), ['cookies' => $jar, 'allow_redirects' => FALSE, 'http_errors' => FALSE, 'verify' => FALSE]);
foreach ($extra as $path) {
  $r = $client->get($base . $path, ['cookies' => $jar, 'allow_redirects' => FALSE, 'http_errors' => FALSE, 'verify' => FALSE]);
  $html = str_replace('\/', '/', (string) $r->getBody());
  preg_match('~<title>([^<]*)~', $html, $t);
  printf("%-45s %d dyn=%-4s link=%d %s\n", $path, $r->getStatusCode(), $r->getHeaderLine('X-Drupal-Dynamic-Cache'), (int) str_contains($html, '/civicrm-cv-ce-vehicles/add'), trim($t[1] ?? ''));
}
