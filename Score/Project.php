<?php

namespace MADSCORE\Score;

/** A project of independently editable scores. */
final class Project {

  private array $items;

  public function __construct(public string $title = 'Untitled', public string $author = '',
    public string $catalog = '') {
    $this->items = [['id' => 'main', 'type' => 'main',
      'score' => self::content(new Score())]];
  }

  public static function fromScore(Score $score): self {
    $project = new self($score->title, $score->author, $score->catalog);
    $project->setScore('main', $score);
    return $project;
  }

  private static function content(Score $score): array {
    $data = $score->data();
    unset($data['title'], $data['author'], $data['catalog']);
    return $data;
  }

  public function data(): array {
    return ['title' => $this->title, 'author' => $this->author,
      'catalog' => $this->catalog, 'items' => $this->items];
  }

  public static function fromData(array $data): self {
    if (count($data) !== 4 || !is_array($data['items'] ?? null)
      || !array_is_list($data['items']) || $data['items'] === []) {
      throw new \DomainException('Invalid MadScore project.');
    }
    $project = new self();
    $project->items = [];
    $seen = [];
    $mainCount = 0;
    foreach ($data['items'] as $item) {
      if (!is_array($item) || !is_string($item['id'] ?? null)
        || (($item['type'] ?? null) === 'motif'
          ? preg_match('/^[A-Z]+$/D', $item['id']) !== 1
          : preg_match('/^[a-z][a-z0-9-]*$/D', $item['id']) !== 1)
        || isset($seen[$item['id']])
        || !in_array($item['type'] ?? null, ['main', 'motif', 'phrase'], true)
        || ($item['type'] === 'main') !== ($item['id'] === 'main')
        || !is_array($item['score'] ?? null)
        || count($item['score']) !== 4
        || array_diff(['bars', 'melodies', 'notes', 'scoreDynamics'], array_keys($item['score'])) !== []
        || array_diff(array_keys($item), ['id', 'type', 'score', 'name', 'music', 'sources']) !== []
        || (isset($item['name']) && !is_string($item['name']))
        || (isset($item['music']) && !is_array($item['music']))
        || (isset($item['sources']) && (!is_array($item['sources']) || !array_is_list($item['sources'])))) {
        throw new \DomainException('Invalid MadScore project item.');
      }
      $seen[$item['id']] = true;
      $mainCount += (int)($item['type'] === 'main');
      $project->items[] = $item;
    }
    if ($mainCount !== 1) {
      throw new \DomainException('A project needs one main score.');
    }
    $meta = [];
    foreach (['title', 'author', 'catalog'] as $field) {
      if (!array_key_exists($field, $data)) {
        throw new \DomainException('Invalid MadScore project metadata.');
      }
      $meta[$field] = $data[$field];
    }
    foreach ($project->items as $item) {
      Score::fromData($item['score'] + $meta);
    }
    [$project->title, $project->author, $project->catalog] = array_values($meta);
    return $project;
  }

  public function items(): array {
    return $this->items;
  }

  public function item(string $id): array {
    foreach ($this->items as $item) {
      if ($item['id'] === $id) {
        return $item;
      }
    }
    throw new \DomainException('The project item does not exist.');
  }

  public function score(string $id): Score {
    $item = $this->item($id);
    return Score::fromData($item['score'] + ['title' => $this->title,
      'author' => $this->author, 'catalog' => $this->catalog]);
  }

  public function setScore(string $id, Score $score): void {
    $this->title = $score->title;
    $this->author = $score->author;
    $this->catalog = $score->catalog;
    foreach ($this->items as &$item) {
      if ($item['id'] === $id) {
        $item['score'] = self::content($score);
        if ($item['type'] === 'motif') {
          $item['music'] = [];
          foreach ($score->melodies as $settings) {
            if (isset($settings['motif'])) {
              $motif = $settings['motif'];
              $item['music'] = ['scale' => $motif['scale'],
                'homeNote' => $motif['homeNote']];
            }
          }
        }
        return;
      }
    }
    throw new \DomainException('The project item does not exist.');
  }

  public function setName(string $id, string $name): void {
    $name = trim(str_replace("\t", ' ', $name));
    if ($name === '' || !mb_check_encoding($name, 'UTF-8')
      || mb_strlen($name) > 80 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
      throw new \DomainException('Item name must be 1–80 characters on one line.');
    }
    foreach ($this->items as &$item) {
      if ($item['id'] === $id && $item['type'] !== 'main') {
        $item['name'] = $name;
        return;
      }
    }
    throw new \DomainException('The project item does not exist.');
  }

  public function first(string $type): ?string {
    foreach ($this->items as $item) {
      if ($item['type'] === $type) {
        return $item['id'];
      }
    }
    return null;
  }

  public function create(string $type): string {
    if (!in_array($type, ['motif', 'phrase'], true)) {
      throw new \DomainException('Only motifs and phrases can be created.');
    }
    $used = array_column($this->items, 'id');
    if ($type === 'motif') {
      $id = 'A';
      while (in_array($id, $used, true)) {
        $id++;
      }
      $name = 'Motif ' . $id;
    } else {
      $number = 1;
      do {
        $id = $type . '-' . $number++;
      } while (in_array($id, $used, true));
      $name = 'Phrase ' . ($number - 1);
    }
    $score = new Score();
    $this->items[] = ['id' => $id, 'type' => $type,
      'name' => $name,
      'music' => $type === 'motif' ? [] : ['scale' => 'C major', 'homeNote' => 'C'],
      'score' => self::content($score)];
    return $id;
  }
}
