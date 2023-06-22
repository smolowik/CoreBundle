<?php

namespace App\Tests\CommonGateway\CoreBundle\Service;

use App\Entity\Gateway as Source;
use CommonGateway\CoreBundle\Service\FileSystemCreateService;
use League\Flysystem\Filesystem;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\ZipArchive\FilesystemZipArchiveProvider;
use League\Flysystem\ZipArchive\ZipArchiveAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

class FileSystemCreateServiceTest extends TestCase
{
    /** @var FileSystemCreateService */
    private $fileSystemService;

    /** @var string|null */
    private ?string $fileName = null;

    protected function setUp(): void
    {
        $this->fileSystemService = new FileSystemCreateService();
    }

    /**
     * Tests the createZipFileFromContent function of the FileSystemCreateService.
     *
     * @return string The created file, will  be used in the next function to delete the file again.
     */
    public function testCreateZipFileFromContent(): string
    {
        // Set up test data
        $content = 'Sample zip file content';

        // Execute the method under test
        $result = $this->fileSystemService->createZipFileFromContent($content);

        // Assertions
        $this->assertFileExists($result);

        return $result;
    }

    /**
     * Tests the removeZipFile function of the FileSystemCreateService.
     *
     * @param string $filename The filename of the file created in the previous function so it can be removed.
     *
     * @return void
     * @depends testCreateZipFileFromContent
     */
    public function testRemoveZipFile(string $filename): void
    {

        // Execute the method under test
        $this->fileSystemService->removeZipFile($filename);

        $this->assertFileDoesNotExist($filename);
    }

    /**
     * Tests the OpenFtpFilesystem function of the FileSystemCreateService
     *
     * @return void
     * @throws \Exception
     */
    public function testOpenFtpFilesystem(): void
    {
        // Set up test data
        $source = new Source();
        $source->setLocation('ftp://example.com:22');
        $source->setUsername('user');
        $source->setPassword('password');

        // Execute the method under test
        $result = $this->fileSystemService->openFtpFilesystem($source);

        // Assertions
        $this->assertInstanceOf(Filesystem::class, $result);
    }

    /**
     * Tests the openZipFilesystem function of the FileSystemCreateService.
     *
     * @return void
     * @throws \Exception
     */
    public function testOpenZipFilesystem(): void
    {
        // Set up test data
        $filename = "/var/tmp/sample.zip";

        $provider = new FilesystemZipArchiveProvider($filename);
        $adapter = new ZipArchiveAdapter($provider);

        // Execute the method under test
        $result = $this->fileSystemService->openZipFilesystem($filename);

        // Assertions
        $this->assertInstanceOf(Filesystem::class, $result);
    }
}