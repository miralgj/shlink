<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\Service;

use Cake\Chronos\Chronos;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Shlinkio\Shlink\Core\Entity\ShortUrl;
use Shlinkio\Shlink\Core\Entity\Tag;
use Shlinkio\Shlink\Core\Model\ShortUrlEdit;
use Shlinkio\Shlink\Core\Model\ShortUrlIdentifier;
use Shlinkio\Shlink\Core\Model\ShortUrlsParams;
use Shlinkio\Shlink\Core\Repository\ShortUrlRepository;
use Shlinkio\Shlink\Core\Service\ShortUrl\ShortUrlResolverInterface;
use Shlinkio\Shlink\Core\Service\ShortUrlService;
use Shlinkio\Shlink\Core\Util\UrlValidatorInterface;
use Shlinkio\Shlink\Rest\Entity\ApiKey;
use ShlinkioTest\Shlink\Core\Util\ApiKeyHelpersTrait;

use function count;

class ShortUrlServiceTest extends TestCase
{
    use ApiKeyHelpersTrait;
    use ProphecyTrait;

    private ShortUrlService $service;
    private ObjectProphecy $em;
    private ObjectProphecy $urlResolver;
    private ObjectProphecy $urlValidator;

    public function setUp(): void
    {
        $this->em = $this->prophesize(EntityManagerInterface::class);
        $this->em->persist(Argument::any())->willReturn(null);
        $this->em->flush()->willReturn(null);

        $this->urlResolver = $this->prophesize(ShortUrlResolverInterface::class);
        $this->urlValidator = $this->prophesize(UrlValidatorInterface::class);

        $this->service = new ShortUrlService(
            $this->em->reveal(),
            $this->urlResolver->reveal(),
            $this->urlValidator->reveal(),
        );
    }

    /**
     * @test
     * @dataProvider provideAdminApiKeys
     */
    public function listedUrlsAreReturnedFromEntityManager(?ApiKey $apiKey): void
    {
        $list = [
            new ShortUrl(''),
            new ShortUrl(''),
            new ShortUrl(''),
            new ShortUrl(''),
        ];

        $repo = $this->prophesize(ShortUrlRepository::class);
        $repo->findList(Argument::cetera())->willReturn($list)->shouldBeCalledOnce();
        $repo->countList(Argument::cetera())->willReturn(count($list))->shouldBeCalledOnce();
        $this->em->getRepository(ShortUrl::class)->willReturn($repo->reveal());

        $paginator = $this->service->listShortUrls(ShortUrlsParams::emptyInstance(), $apiKey);

        self::assertCount(4, $paginator);
        self::assertCount(4, $paginator->getCurrentPageResults());
    }

    /**
     * @test
     * @dataProvider provideAdminApiKeys
     */
    public function providedTagsAreGetFromRepoAndSetToTheShortUrl(?ApiKey $apiKey): void
    {
        $shortUrl = $this->prophesize(ShortUrl::class);
        $shortUrl->setTags(Argument::any())->shouldBeCalledOnce();
        $shortCode = 'abc123';
        $this->urlResolver->resolveShortUrl(new ShortUrlIdentifier($shortCode), $apiKey)
            ->willReturn($shortUrl->reveal())
            ->shouldBeCalledOnce();

        $tagRepo = $this->prophesize(EntityRepository::class);
        $tagRepo->findOneBy(['name' => 'foo'])->willReturn(new Tag('foo'))->shouldBeCalledOnce();
        $tagRepo->findOneBy(['name' => 'bar'])->willReturn(null)->shouldBeCalledOnce();
        $this->em->getRepository(Tag::class)->willReturn($tagRepo->reveal());

        $this->service->setTagsByShortCode(new ShortUrlIdentifier($shortCode), ['foo', 'bar'], $apiKey);
    }

    /**
     * @test
     * @dataProvider provideShortUrlEdits
     */
    public function updateMetadataByShortCodeUpdatesProvidedData(
        int $expectedValidateCalls,
        ShortUrlEdit $shortUrlEdit,
        ?ApiKey $apiKey
    ): void {
        $originalLongUrl = 'originalLongUrl';
        $shortUrl = new ShortUrl($originalLongUrl);

        $findShortUrl = $this->urlResolver->resolveShortUrl(
            new ShortUrlIdentifier('abc123'),
            $apiKey,
        )->willReturn($shortUrl);
        $flush = $this->em->flush()->willReturn(null);

        $result = $this->service->updateMetadataByShortCode(new ShortUrlIdentifier('abc123'), $shortUrlEdit, $apiKey);

        self::assertSame($shortUrl, $result);
        self::assertEquals($shortUrlEdit->validSince(), $shortUrl->getValidSince());
        self::assertEquals($shortUrlEdit->validUntil(), $shortUrl->getValidUntil());
        self::assertEquals($shortUrlEdit->maxVisits(), $shortUrl->getMaxVisits());
        self::assertEquals($shortUrlEdit->longUrl() ?? $originalLongUrl, $shortUrl->getLongUrl());
        $findShortUrl->shouldHaveBeenCalled();
        $flush->shouldHaveBeenCalled();
        $this->urlValidator->validateUrl(
            $shortUrlEdit->longUrl(),
            $shortUrlEdit->doValidateUrl(),
        )->shouldHaveBeenCalledTimes($expectedValidateCalls);
    }

    public function provideShortUrlEdits(): iterable
    {
        yield 'no long URL' => [0, ShortUrlEdit::fromRawData(
            [
                'validSince' => Chronos::parse('2017-01-01 00:00:00')->toAtomString(),
                'validUntil' => Chronos::parse('2017-01-05 00:00:00')->toAtomString(),
                'maxVisits' => 5,
            ],
        ), null];
        yield 'long URL' => [1, ShortUrlEdit::fromRawData(
            [
                'validSince' => Chronos::parse('2017-01-01 00:00:00')->toAtomString(),
                'maxVisits' => 10,
                'longUrl' => 'modifiedLongUrl',
            ],
        ), new ApiKey()];
        yield 'long URL with validation' => [1, ShortUrlEdit::fromRawData(
            [
                'longUrl' => 'modifiedLongUrl',
                'validateUrl' => true,
            ],
        ), null];
    }
}
