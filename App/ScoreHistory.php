<?php

namespace MADSCORE\App;

use MADSCORE\Score\Score;

/** Keeps chronological, score-wide undo and redo snapshots. */
final class ScoreHistory {

  private const LIMIT = 100;
  private array $current;
  private array $undo = [];
  private array $redo = [];

  public function __construct(Score $score) {
    $this->reset($score);
  }

  public function reset(Score $score): void {
    $this->current = $score->data();
    $this->undo = $this->redo = [];
  }

  /** Accept cursor-created empty bars without adding an undo step. */
  public function rebase(Score $score): void {
    $this->current = $score->data();
  }

  /** Record one completed edit, including changes to several melodies. */
  public function observe(Score $score, int $melody = 0): bool {
    $next = $score->data();
    if ($next === $this->current) {
      return false;
    }
    $this->undo[] = $this->current;
    if (count($this->undo) > self::LIMIT) {
      array_shift($this->undo);
    }
    $this->current = $next;
    $this->redo = [];
    return true;
  }

  public function undo(Score $score, int $melody = 0): bool {
    $this->observe($score);
    if ($this->undo === []) {
      return false;
    }
    $target = array_pop($this->undo);
    $this->redo[] = $this->current;
    $this->restore($score, $target);
    return true;
  }

  public function redo(Score $score, int $melody = 0): bool {
    $this->observe($score);
    if ($this->redo === []) {
      return false;
    }
    $target = array_pop($this->redo);
    $this->undo[] = $this->current;
    $this->restore($score, $target);
    return true;
  }

  private function restore(Score $score, array $data): void {
    $valid = Score::fromData($data);
    $score->title = $valid->title;
    $score->author = $valid->author;
    $score->catalog = $valid->catalog;
    $score->bars = $valid->bars;
    $score->notes = $valid->notes;
    $score->melodies = $valid->melodies;
    $score->scoreDynamics = $valid->scoreDynamics;
    $this->current = $valid->data();
  }
}
