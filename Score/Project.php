<?php

namespace MADSCORE\Score;

/** A project of independently editable scores. */
final class Project {

  private array $items;

  public function __construct(public string $title = 'Untitled', public string $author = '',
    public string $catalog = '') {
    $this->items = [['id' => 'main',
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
        || ($item['id'] !== 'main' && preg_match('/^[A-Z]+$/D', $item['id']) !== 1)
        || isset($seen[$item['id']])
        || !is_array($item['score'] ?? null)
        || count($item['score']) !== 4
        || array_diff(['bars', 'melodies', 'notes', 'scoreDynamics'], array_keys($item['score'])) !== []
        || array_diff(array_keys($item), ['id', 'score', 'name', 'music', 'pattern']) !== []
        || (array_key_exists('pattern', $item)
          && ($item['id'] !== 'main' || !is_string($item['pattern'])
            || !mb_check_encoding($item['pattern'], 'UTF-8')))
        || (isset($item['name']) && !is_string($item['name']))
        || (isset($item['music']) && $item['music'] !== []
          && HarmonicContext::validate($item['music']) !== $item['music'])) {
        throw new \DomainException('Invalid MadScore project item.');
      }
      $seen[$item['id']] = true;
      $mainCount += (int)($item['id'] === 'main');
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

  /** The main score's editable motif arrangement, if one has been entered. */
  public function pattern(): ?string {
    return $this->item('main')['pattern'] ?? null;
  }

  public function setPattern(string $pattern): void {
    if (!mb_check_encoding($pattern, 'UTF-8')) {
      throw new \DomainException('The motif pattern must be UTF-8 text.');
    }
    foreach ($this->items as &$item) {
      if ($item['id'] === 'main') {
        $item['pattern'] = $pattern;
        return;
      }
    }
    throw new \LogicException('The project has no main score.');
  }

  public function setScore(string $id, Score $score): void {
    $this->title = $score->title;
    $this->author = $score->author;
    $this->catalog = $score->catalog;
    foreach ($this->items as &$item) {
      if ($item['id'] === $id) {
        $item['score'] = self::content($score);
        if ($id !== 'main') {
          $item['music'] = $score->sectionMusic(0) ?? [];
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
      if ($item['id'] === $id && $id !== 'main') {
        $item['name'] = $name;
        return;
      }
    }
    throw new \DomainException('The project item does not exist.');
  }

  public function first(): ?string {
    foreach ($this->items as $item) {
      if ($item['id'] !== 'main') {
        return $item['id'];
      }
    }
    return null;
  }

  public function create(): string {
    $used = array_column($this->items, 'id');
    $id = 'A';
    while (in_array($id, $used, true)) {
      $id++;
    }
    $name = 'Motif ' . $id;
    $score = new Score();
    $this->items[] = ['id' => $id,
      'name' => $name,
      'music' => [],
      'score' => self::content($score)];
    return $id;
  }
}
