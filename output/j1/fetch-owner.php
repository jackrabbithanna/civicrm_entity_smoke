<?php
// Fetches contact 7's page as uid 1 twice; prints cache headers and whether the add-vehicle link is there.
use Drupal\user\Entity\User;
use GuzzleHttp\Cookie\CookieJar;
$base = 'https://drupal-civi-composer-exp-d11.ddev.site';
$client = \Drupal::httpClient();
$jar = new CookieJar();
$client->get($base . parse_url(user_pass_reset_url(User::load(1)) . '/login', PHP_URL_PATH), ['cookies' => $jar, 'allow_redirects' => FALSE, 'http_errors' => FALSE, 'verify' => FALSE]);
$label = $extra[0] ?? 'x';
foreach ([1, 2] as $n) {
  $r = $client->get($base . '/civicrm-contact/7', ['cookies' => $jar, 'http_errors' => FALSE, 'verify' => FALSE]);
  $html = (string) $r->getBody();
  file_put_contents(__DIR__ . "/owner-$label-$n.html", $html);
  printf("%s #%d: %d dyn=%s link=%d\n", $label, $n, $r->getStatusCode(), $r->getHeaderLine('X-Drupal-Dynamic-Cache'), (int) str_contains($html, '/civicrm-cv-ce-vehicles/add'));
}
