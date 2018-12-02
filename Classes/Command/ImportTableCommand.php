<?php
declare(strict_types = 1);

namespace MaxServ\YamlConfiguration\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ImportTableCommand extends AbstractTableCommand
{

    /**
     * Fields used to match configurations to database records
     *
     * @var array
     */
    protected $matchFields = [];

    protected function configure(): void
    {
        $this
            ->setDescription('Imports data into tables from YAML configuration')
            ->addArgument(
                'table',
                InputArgument::REQUIRED,
                'The name of the table into which you want to import'
            )
            ->addArgument(
                'matchFields',
                InputArgument::REQUIRED,
                'Comma separated list of fields used to match configurations to database records.'
            )
            ->addArgument(
                'file',
                InputArgument::OPTIONAL,
                'Path to the yml file you wish to import. If none is given, all yml files in directories named \'Configuration\' will be parsed',
                null
            );
    }

    /**
     * The command main method
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|void|null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $this->table = trim($input->getArgument('table'));
        $this->matchFields = GeneralUtility::trimExplode(',', $input->getArgument('matchFields'), true);
        $this->file = $input->getArgument('file');
        // Print information about the command and passed arguments
        $io = new SymfonyStyle($input, $output);
        $io->title($this->getName());
        $io->table(
            ['Table Name', 'Matching Fields', 'File Path'],
            [
                [
                    $input->getArgument('table'),
                    $input->getArgument('matchFields'),
                    $input->getArgument('file') ?? '<info>no path given</info>'
                ]
            ]
        );

        $this->importData($io, $input, $output);

    }

    /**
     * @param SymfonyStyle $io
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function importData(SymfonyStyle $io, InputInterface $input, OutputInterface $output): void
    {
        $table = $this->table;
        $matchFields = $this->matchFields;
        $columnNames = $this->getColumnNames();
        $this->doMatchFieldsExists($matchFields, $columnNames, $io);
        $queryBuilder = $this->queryBuilderForTable($table);
        $countUpdates = 0;
        $countInserts = 0;
        if ($this->file === null) {
            $configurationFiles = $this->findYamlFiles();
        } else {
            $configurationFiles = [$this->file];
        }

        $io->title('Importing ' . $table . ' configuration');

        foreach ($configurationFiles as $configurationFile) {
            $configuration = $this->parseConfigurationFile($configurationFile);
            $io->note('Parsing: ' . str_replace(Environment::getPublicPath() . '/', '', $configurationFile));
            $records = $this->getDataConfiguration($configuration, $table);
            $io->writeln('Found ' . count($records) . ' records in the parsed file.');
            foreach ($records as $record) {
                $record = $this->flattenYamlFields($record);
                $row = false;
                $whereClause = false;
                $queryResult = false;
                $matchClauseParts = [];
                foreach ($matchFields as $matchField) {
                    if (isset($record[$matchField])) {
                        // @TODO: Use named parameters based on the column configuration in the DB
                        $matchClauseParts[] = [
                            $matchField,
                            $record[$matchField]];
                    }
                }
                if (!empty($matchClauseParts)) {
                    $queryBuilderWithoutRestrictions = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
                    $queryBuilderWithoutRestrictions->getRestrictions()->removeAll();
                    $row = $queryBuilderWithoutRestrictions
                        ->select('*')
                        ->from($table);
                    $whereClause = [];
                    foreach ($matchClauseParts as $matchClausePart) {
                        $whereClause[] = $queryBuilder->expr()->andX(
                            $queryBuilder->expr()->eq(
                                $matchClausePart[0],
                                // @TODO: Use named parameters based on the column configuration in the DB
                                $matchClausePart[1]
                            )
                        );
                    }
                    $row = $row->where(...$whereClause)->execute()->fetch();
                }
                if ($row) {
                    // Update row as the matched row exists in the table
                    // @TODO: re-implement beUserMatchGroupByTitle()
                    $record = $this->updateTimeFields($record, $columnNames, ['tstamp']);
                    $updateRecord = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table)
                        ->update(
                            $table,
                            $record,
                            $this->convertArrayToKeyValuePairArray($matchClauseParts)
                        );
                    if ($updateRecord) {
                        $countUpdates++;
                    }
                } else {
                    // Insert new row as no matched row exists in the table
                    $record = $this->updateTimeFields($record, $columnNames, ['crdate', 'tstamp'], true);
                    $insertRecord = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table)
                        ->insert(
                            $table,
                            $record
                        );
                    if ($insertRecord) {
                        $countInserts++;
                    }
                }
            }
            $io->newLine();
            $io->listing(
                [
                    $countUpdates . ' records were updated. ',
                    $countInserts . ' records where newly inserted.'
                ]
            );
        }

        $io->success('Successfully finished the import of ' . \count($configurationFiles) . ' configuration file(s).');
    }

    /**
     * Check if configuration file exists and returns the result of the Yaml parser
     *
     * @param $configurationFile
     * @return array|null
     */
    protected function parseConfigurationFile($configurationFile): ?array
    {
        $configuration = null;
        if (!empty($configurationFile) && is_file($configurationFile)) {
            $configuration = Yaml::parseFile($configurationFile);
        }

        return $configuration;
    }

    /**
     * Flatten yaml fields into string values
     *
     * @param array $row
     * @param string $glue
     *
     * @return array
     */
    protected function flattenYamlFields(array $row, $glue = ','): array
    {
        $flat = [];
        foreach ($row as $key => $value) {
            if (\is_array($value)) {
                $flat[$key] = implode($glue, $value);
            } else {
                $flat[$key] = $value;
            }
        }

        return $flat;
    }

}