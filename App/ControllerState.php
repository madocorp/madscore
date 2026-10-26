<?php

namespace MADSCORE\App;

use MADSCORE\Play\PlaybackTimeline;
use MADSCORE\Play\SheetCache;
use MADSCORE\Score\Pitch;
use MADSCORE\Score\Project;
use MADSCORE\Score\Score;
use SPTK\Core\Window;
use SPTK\Layout\LayoutLeaf;

/** Shared editor state for the focused static controllers. */
abstract class ControllerState {
  protected const SCORE_PATTERN_HELP = "Create motifs first. Then replace this text with the list of motifs. Use this format: 'A B A1 | A B1 A2'. Then press the build button.";
  protected static Score $score;
  protected static Project $project;
  protected static string $activeItemId = 'main';
  protected static Window $window;
  protected static LayoutLeaf $sheet;
  /** @var array<string, LayoutLeaf> */
  protected static array $sheets = [];
  protected static int $columns = 0;
  protected static int $firstBar = 0;
  protected static int $previewPage = 0;
  protected static ?string $previewImagePath = null;
  protected static string $view = 'editor';
  protected static int $bar = 0;
  protected static int $slot = 0;
  protected static int $octave = Pitch::MIDDLE_C_BAND;
  protected static int $melody = 0;
  protected static int $cursorLength = 2;
  protected static bool $cursorLengthExplicit = false;
  protected static int $lastCursorEndBar = 0;
  protected static int $pitchPosition = 4;
  protected static int $displayedBar = -1;
  protected static string $displayedReadout = '';
  protected static ?MelodySelection $selection = null;
  protected static bool $sessionLoadFailed = false;
  protected static ?string $playbackMode = null;
  protected static ?array $playbackFrame = null;
  protected static ?PlaybackTimeline $playbackTimeline = null;
  protected static ?SheetCache $playbackCache = null;
  protected static ?int $playbackTimer = null;
  protected static int $playbackBeatIndex = -1;
  protected static float $playbackEndSeconds = 0.0;
  protected static array $playbackHeldKeys = [];
  protected static ?int $playbackDrainTimer = null;
  protected static ?string $playbackDrainView = null;
  protected static bool $playbackDrainBatch = false;
  protected static bool $playbackSuppressText = false;
  protected static ?array $playbackReturn = null;
  protected static string $playbackSound = 'midi';
  protected static ?int $pendingDeleteMelody = null;
  protected static ?ScoreHistory $history = null;
  protected static ?array $musicClipboard = null;
  protected static bool $composerOpen = false;
  protected static bool $chordOpen = false;
  protected static ?string $chordChoiceOpen = null;
  protected static string $chordScaleSelection = '';
  protected static string $chordHomeNoteSelection = '';
  protected static array $lastChordSettings = [];
  protected static bool $composerListOpen = false;
  protected static bool $composerGenerateOpen = false;
  protected static ?string $composerChoiceOpen = null;
  protected static bool $composerKeyOpen = false;
  protected static ?string $composerKeyChoiceOpen = null;
  protected static string $composerKeyScale = 'Major';
  protected static string $composerKeyHomeNote = 'C';
  protected static int $composerKeyBar = 0;
  protected static bool $motifVersionPickerOpen = false;
  protected static ?string $motifVersionAlgorithm = null;
  protected static int $motifVersionSource = 0;
  protected static string $composerScaleSelection = 'random';
  protected static string $composerHomeNoteSelection = 'random';
  protected static string $composerTypeSelection = 'random-step';
  protected static int $composerGenerateTarget = 0;
  protected static ?string $recentMotifId = null;
  /** @var array<string, array<string, int|bool>> */
  protected static array $itemCursors = [];
  protected static ?Score $pendingBuiltScore = null;

}
