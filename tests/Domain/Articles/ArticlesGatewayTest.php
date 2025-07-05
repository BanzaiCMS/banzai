<?php /** @noinspection PhpParamsInspection */
declare(strict_types=1);

namespace Banzai\Domain\Tests\Articles;

use Flux\Config\Config;
use Flux\Database\DatabaseInterface;
use Flux\Logger\LoggerInterface;
use Banzai\Core\Application;
use Banzai\Domain\Articles\ArticlesGateway;
use Banzai\Domain\Folders\FoldersGateway;
use Banzai\I18n\Locale\LocaleServiceInterface;
use Twig\Environment as Twig;
use PHPUnit\Framework\TestCase;

/**
 * Class ArticlesGatewayTest
 */
class ArticlesGatewayTest extends TestCase
{
    /**
     * Create a new ArticlesGateway instance with mocked dependencies
     *
     * @return array An array containing the ArticlesGateway instance and all mocked dependencies
     */
    private function createArticlesGatewayWithMocks(): array
    {
        $dbStub = $this->createMock(DatabaseInterface::class);
        $loggerStub = $this->createMock(LoggerInterface::class);
        $configStub = $this->createMock(Config::class);
        $twigStub = $this->createMock(Twig::class);
        $localeStub = $this->createMock(LocaleServiceInterface::class);
        $foldersStub = $this->createMock(FoldersGateway::class);
        $applicationStub = $this->createMock(Application::class);


        $configStub->method('get')
            ->willReturn('-');

        $applicationStub->method('isStaging')
            ->willReturn(false);


        $articlesGateway = new ArticlesGateway(
            $applicationStub,
            $dbStub,
            $loggerStub,
            $configStub,
            $twigStub,
            $localeStub,
            $foldersStub
        );

        return [
            'gateway' => $articlesGateway,
            'db' => $dbStub,
            'logger' => $loggerStub,
            'config' => $configStub,
            'twig' => $twigStub,
            'locale' => $localeStub,
            'folders' => $foldersStub,
            'application' => $applicationStub
        ];
    }
    /**
     * Test that limitString correctly limits a string to the specified length
     */
    public function testLimitString()
    {
        $mocks = $this->createArticlesGatewayWithMocks();
        $articlesGateway = $mocks['gateway'];

        // Test with string shorter than limit
        $shortString = "Short string";
        $result = $articlesGateway->limitString($shortString, 20, "...");
        $this->assertEquals($shortString, $result);

        // Test with string longer than limit
        $longString = "This is a very long string that should be truncated";
        $result = $articlesGateway->limitString($longString, 20, "...");
        $this->assertEquals("This is a very...", $result);

        // Test with limit at word boundary
        $result = $articlesGateway->limitString($longString, 22, "...");
        $this->assertEquals("This is a very long...", $result);
    }

    /**
     * Test that makeSEOCleanURL correctly cleans a URL
     */
    public function testMakeSEOCleanURL()
    {
        $mocks = $this->createArticlesGatewayWithMocks();
        $articlesGateway = $mocks['gateway'];

        // Test with special characters
        $dirtyUrl = "This is a URL with special characters: äöü!@#$%^&*()";
        $result = $articlesGateway->makeSEOCleanURL($dirtyUrl);
        $this->assertEquals("this-is-a-url-with-special-characters-aeoeue", $result);

        // Test with spaces
        $spacedUrl = "URL with spaces";
        $result = $articlesGateway->makeSEOCleanURL($spacedUrl);
        $this->assertEquals("url-with-spaces", $result);

        // Test with uppercase
        $uppercaseUrl = "UPPERCASE URL";
        $result = $articlesGateway->makeSEOCleanURL($uppercaseUrl);
        $this->assertEquals("uppercase-url", $result);
    }

    /**
     * Test that getMonthnameYear returns the correct month name and year
     */
    public function testGetMonthnameYear()
    {
        $mocks = $this->createArticlesGatewayWithMocks();
        $articlesGateway = $mocks['gateway'];

        // Test January 2023
        $result = $articlesGateway->getMonthnameYear(2023, 1);
        $this->assertEquals("Januar 2023", $result);

        // Test December 2022
        $result = $articlesGateway->getMonthnameYear(2022, 12);
        $this->assertEquals("Dezember 2022", $result);
    }

    /**
     * Test that getArticleIDFromURL returns 0 when catid is 0
     */
    public function testGetArticleIDFromURLReturnsZeroWhenCatidIsZero()
    {
        $mocks = $this->createArticlesGatewayWithMocks();
        $articlesGateway = $mocks['gateway'];

        $mocks['logger']->expects($this->once())
            ->method('error')
            ->with($this->equalTo('catid < 1'), $this->anything());

        $result = $articlesGateway->getArticleIDFromURL('test-url', 0, false);
        $this->assertEquals(0, $result);
    }
}
