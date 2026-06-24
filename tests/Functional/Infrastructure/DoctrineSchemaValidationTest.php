<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @brief Guard Doctrine mapping and database schema alignment in CI.
 */
final class DoctrineSchemaValidationTest extends KernelTestCase
{
    /**
     * @brief Entity metadata must be valid and database schema must match mappings.
     *
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function testSchemaValidateCommandSucceeds(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $command = $application->find('doctrine:schema:validate');

        $this->enableDoctrineMigrationSchemaFilter($kernel, $command);

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode, trim($tester->getDisplay()));
        self::assertStringContainsString('The mapping files are correct', $tester->getDisplay());
        self::assertStringContainsString('The database schema is in sync with the mapping files', $tester->getDisplay());
    }

    /**
     * @brief Storage entities must expose class-level Doctrine index metadata.
     *
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function testSharedFileEntityDeclaresQueryableIndexes(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $metadata = $entityManager->getClassMetadata('App\Entity\SharedFile');

        self::assertArrayHasKey('idx_shared_file_is_public', $metadata->table['indexes'] ?? []);
        self::assertArrayHasKey('idx_shared_file_expires_at', $metadata->table['indexes'] ?? []);
    }

    /**
     * @brief Mirror bin/console bootstrap so migration metadata table is ignored during validate.
     *
     * @param \Symfony\Component\HttpKernel\KernelInterface $kernel Booted kernel.
     * @param Command $command Console command about to run.
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    private function enableDoctrineMigrationSchemaFilter(object $kernel, Command $command): void
    {
        $dispatcher = $kernel->getContainer()->get('event_dispatcher');
        $dispatcher->dispatch(
            new ConsoleCommandEvent($command, new ArrayInput([]), new BufferedOutput()),
            ConsoleEvents::COMMAND,
        );
    }
}
