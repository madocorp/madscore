<?php

namespace MADSCORE\App;

use MADSCORE\Score\Score;

/** Keeps independent undo and redo snapshots for each melody. */
final class MelodyHistory {

  private const LIMIT = 100;

  private array $current = [];
  private array $undo = [];
  private array $redo = [];

  public function __construct(Score $score) {
    $this->reset($score);
  }

  /** Start fresh histories for the melodies in a loaded score. */
  public function reset(Score $score): void {
    $this->current = [];
    $this->undo = [];
    $this->redo = [];
    foreach (array_keys($score->melodies) as $melody) {
      $this->current[$melody] = self::snapshot($score, $melody);
      $this->undo[$melody] = [];
      $this->redo[$melody] = [];
    }
  }

  /** Record a changed melody and discard only that melody's redo branch. */
  public function observe(Score $score, int $melody): bool {
    $next = self::snapshot($score, $melody);
    if (!isset($this->current[$melody])) {
      $this->current[$melody] = $next;
      $this->undo[$melody] = [];
      $this->redo[$melody] = [];
      return false;
    }
    if ($next === $this->current[$melody]) {
      return false;
    }
    $this->undo[$melody][] = $this->current[$melody];
    if (count($this->undo[$melody]) > self::LIMIT) {
      array_shift($this->undo[$melody]);
    }
    $this->current[$melody] = $next;
    $this->redo[$melody] = [];
    return true;
  }

  /** Restore the previous state of one melody. */
  public function undo(Score $score, int $melody): bool {
    $this->observe($score, $melody);
    if ($this->undo[$melody] === []) {
      return false;
    }
    $target = end($this->undo[$melody]);
    self::restore($score, $melody, $target);
    array_pop($this->undo[$melody]);
    $this->redo[$melody][] = $this->current[$melody];
    $this->current[$melody] = self::snapshot($score, $melody);
    return true;
  }

  /** Reapply the next state of one melody. */
  public function redo(Score $score, int $melody): bool {
    $this->observe($score, $melody);
    if ($this->redo[$melody] === []) {
      return false;
    }
    $target = end($this->redo[$melody]);
    self::restore($score, $melody, $target);
    array_pop($this->redo[$melody]);
    $this->undo[$melody][] = $this->current[$melody];
    $this->current[$melody] = self::snapshot($score, $melody);
    return true;
  }

  /** Give a newly appended melody its own empty history. */
  public function addedMelody(Score $score, int $melody): void {
    $this->current[$melody] = self::snapshot($score, $melody);
    $this->undo[$melody] = [];
    $this->redo[$melody] = [];
  }

  /** Remove one history and move later histories with their melody indices. */
  public function removedMelody(int $melody): void {
    array_splice($this->current, $melody, 1);
    array_splice($this->undo, $melody, 1);
    array_splice($this->redo, $melody, 1);
  }

  /** Copy only the selected melody's notes and settings. */
  private static function snapshot(Score $score, int $melody): array {
    if (!isset($score->melodies[$melody])) {
      throw new \OutOfBoundsException('The melody does not exist.');
    }
    return [
      'notes' => array_values(array_map(static function (array $note): array {
        unset($note['melody']);
        return $note;
      }, array_filter($score->notes, static fn (array $note): bool => $note['melody'] === $melody))),
      'settings' => $score->melodies[$melody],
    ];
  }

  /** Validate a merged score before replacing the selected melody's state. */
  private static function restore(Score $score, int $melody, array $target): void {
    $data = $score->data();
    $data['notes'] = array_values(array_filter($data['notes'], static fn (array $note): bool => $note['melody'] !== $melody));
    foreach ($target['notes'] as $note) {
      $note['melody'] = $melody;
      $data['notes'][] = $note;
      while (count($data['bars']) <= $note['bar']) {
        $bar = $data['bars'][count($data['bars']) - 1];
        $bar['sectionLeader'] = false;
        $bar['note'] = '';
        $data['bars'][] = $bar;
      }
    }
    $data['melodies'][$melody] = $target['settings'];
    $validated = Score::fromData($data);
    $score->notes = $validated->notes;
    $score->melodies[$melody] = $validated->melodies[$melody];
    $score->bars = $validated->bars;
  }
}
