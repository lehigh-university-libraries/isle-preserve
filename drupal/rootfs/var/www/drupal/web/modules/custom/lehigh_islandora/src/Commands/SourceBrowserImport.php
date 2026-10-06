<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lehigh_islandora\JournalBrowser\SourceBrowserImporter;
use Drupal\node\NodeInterface;
use Drush\Commands\DrushCommands;

/**
 * Imports reviewed browser metadata without relying on old runtime services.
 */
final class SourceBrowserImport extends DrushCommands {

  /**
   * Constructs the commands.
   */
  public function __construct(
    protected SourceBrowserImporter $importer,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Validate/import all three exported Gunn index tabs.
   *
   * @param int $source
   *   Source collection node ID.
   * @param string $directory
   *   Directory containing gunn_subjects.csv, gunn_index.csv, gunn_volumes.csv.
   * @param array $options
   *   Command options.
   *
   * @command source-browser:import-index
   *
   * @option apply Write the validated import. Without this flag, only preflight.
   */
  public function index(int $source, string $directory, array $options = ['apply' => FALSE]): int {
    return $this->report($this->importer->importIndex($this->source($source), $directory, (bool) $options['apply']));
  }

  /**
   * Validate/import exact newspaper PID/date/volume/issue mappings.
   *
   * @param int $source
   *   Source collection node ID.
   * @param string $path
   *   Path to legacy-issue-dates.csv.
   * @param string $collection
   *   Collection key: spress, vfair, or nyleader.
   * @param array $options
   *   Command options.
   *
   * @command source-browser:import-dates
   *
   * @option apply Write the validated import. Without this flag, only preflight.
   * @option overwrite Replace conflicting existing metadata after review.
   */
  public function dates(
    int $source,
    string $path,
    string $collection,
    array $options = [
      'apply' => FALSE,
      'overwrite' => FALSE,
    ],
  ): int {
    return $this->report($this->importer->importDates($this->source($source), $path, $collection, (bool) $options['apply'], (bool) $options['overwrite']));
  }

  /**
   * Import a Gunn transcript CSV or numeric page transcript directory.
   *
   * @param int $source
   *   Source collection node ID.
   * @param string $directory
   *   CSV with node_id, field_pid, Transcript URL, or numeric .txt directory.
   * @param array $options
   *   Command options.
   *
   * @command source-browser:import-transcriptions
   *
   * @option apply Write the validated import. Without this flag, only preflight.
   */
  public function transcriptions(int $source, string $directory, array $options = ['apply' => FALSE]): int {
    $node = $this->source($source);
    $report = is_file($directory) || str_ends_with(strtolower($directory), '.csv')
      ? $this->importer->importTranscriptCsv($node, $directory, (bool) $options['apply'])
      : $this->importer->importTranscriptions($node, $directory, (bool) $options['apply']);
    return $this->report($report);
  }

  /**
   * Resolves a real collection before the importer validates its target PIDs.
   */
  protected function source(int $id): NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->load($id);
    if (!$node instanceof NodeInterface || !lehigh_site_support_identify_collection($node, TRUE)) {
      throw new \InvalidArgumentException('Source must be an existing collection node.');
    }
    return $node;
  }

  /**
   * Prints a concise audit report and fails when any row is unmapped.
   */
  protected function report(array $report): int {
    $this->output()->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $report['invalid'] ? 1 : 0;
  }

}
