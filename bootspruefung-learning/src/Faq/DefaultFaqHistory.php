<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Faq;

defined('ABSPATH') || exit;

/**
 * Frühere Standardstände der FAQs (Prüfsumme => Status beim Import), je FAQ-Nummer. Damit erkennt
 * FaqImporter::updateDefaults() unveränderte FAQs auch auf Installationen, die vor der Prüfsumme
 * _bp_faq_default_hash importiert wurden, und unveränderte FAQs früherer Stände beim Wechsel auf einen neuen.
 *
 * Stände: Datenstand 1 und 2 (VERSION '1'/'2', Text wie DefaultFaqs vom 10.11.2026 vor der Inhaltsmoderation) haben
 * dieselben Texte – die Prüfsumme je Nummer gilt für beide. Zusätzlich die Zwischenstände von FAQ 22 (vor „Link auf
 * die AGB direkt im Satz“) und FAQ 85 (vor „Simulator im Kombikurs präzisiert“). FAQ 13 bleibt eingetragen, damit sie
 * als entfernte Standard-FAQ (DefaultFaqs::REMOVED) unverändert erkannt wird.
 *
 * Nur ergänzen, nie entfernen: Wer einen Text in DefaultFaqs ändert, hängt vorher den bisherigen Stand an
 * (FaqImporter::hash(Frage, FaqImporter::content(FAQ)) => FaqImporter::statusFor(FAQ)).
 */
final class DefaultFaqHistory
{
    /** @var array<int,array<string,string>> */
    public const HASHES = [
        1 => ['339838dfb3f670c9eb694df79a8da9f0' => 'publish'],
        2 => ['fd8e0dd989acc4f1965ec09b862ae72f' => 'publish'],
        3 => ['dad82688107153be9a33bf9d4d2bc91e' => 'publish'],
        4 => ['5704bd4f34e23870bb187286469a5936' => 'publish'],
        5 => ['d810b05556adb8887304d2041379ea89' => 'publish'],
        6 => ['9cdc978af3a9e96179ae448a0801c226' => 'publish'],
        7 => ['c96e0374bae506b595c2654d41e47a2b' => 'publish'],
        8 => ['cdfc78a891b9d4c412f7adf3546f891d' => 'publish'],
        9 => ['fde343116c10d5d18ed746d1ecb9ec3d' => 'draft'],
        10 => ['a7032b218179b8c16161e3aaf0e79d7f' => 'publish'],
        11 => ['b893384d04ef1b3c62d230370336f81f' => 'draft'],
        12 => ['87fa77d136b0e005b57bd78a2133ead0' => 'draft'],
        13 => ['0807493c565c4f5f17d11df60947cb3d' => 'draft'],
        14 => ['f7ac1c68b660cfbfca335c8e533c0b3c' => 'draft'],
        15 => ['8ec0c3794a67c08ae3ab5da145ebde2d' => 'publish'],
        16 => ['b1ec1ab7c3354db60617f753adbe61a0' => 'publish'],
        17 => ['7e9bab1148db9f6a1b5fe6ca74b3d580' => 'publish'],
        18 => ['2703031a1b25adbcb2291bfcb89351bf' => 'publish'],
        19 => ['8cbb7f2caba73c15a97de01a772bdf89' => 'draft'],
        20 => ['0f6b8e0eeb687f585b8ed49e386d738f' => 'draft'],
        21 => ['546266d9dbf39279f78734f89de77e10' => 'draft'],
        22 => ['2eaf2c5ef33fa84615bb0b933ebb3dac' => 'publish', 'b08bc3c3cdc5e9d809458e44b669d801' => 'publish'],
        23 => ['24b92e2ca88a425ba80fb4ebdeaf00a1' => 'draft'],
        24 => ['e89cdf4d4d192a6be80527da538f2a4c' => 'publish'],
        25 => ['437aecfec47ac3dc071713e19372554e' => 'publish'],
        26 => ['192e8214ca5a87155bee62b66f0dcc01' => 'publish'],
        27 => ['12a3f10b2cd749ac7aeada26326fabca' => 'publish'],
        28 => ['560f946305d43a60e55d675655cd67b5' => 'publish'],
        29 => ['47046e7ab18d9eaf239848da6ea2f514' => 'publish'],
        30 => ['c2e3c85cb906c6e88886c73add6516a2' => 'publish'],
        31 => ['4cb49257e277982a04225348a2e5f62b' => 'publish'],
        32 => ['2a820a1cbd37098b0a79932e52f5ba4a' => 'publish'],
        33 => ['eeeb759b2812c5ebe7bb6d493279b9d6' => 'publish'],
        34 => ['573497e2931781057df7557706e373c3' => 'publish'],
        35 => ['9a25feb3c983b937720f9687f31e977d' => 'publish'],
        36 => ['e24fe770ae32b400f996aaa64cdf616a' => 'publish'],
        37 => ['0a43ab072c467d44eb8594d3f5c943c3' => 'publish'],
        38 => ['3fb4d7994d26799528b254d13e3c8e76' => 'publish'],
        39 => ['22a36654d47359008a25f60ddc3f4107' => 'publish'],
        40 => ['b01aec803c8a05c1364086047105439d' => 'publish'],
        41 => ['05384ddfb8780a6bfa546b477ce525e2' => 'publish'],
        42 => ['f41987e8af0f027b0e3c3921ac75cdec' => 'publish'],
        43 => ['0298536c84cd209da1e62f16b692d812' => 'publish'],
        44 => ['0bfa4891a25b58f7a37500e73e83a97d' => 'publish'],
        45 => ['8d8bf195a0ee9129c354d367de1f5f62' => 'publish'],
        46 => ['8fc02b199b708a99110b741860997cdc' => 'publish'],
        47 => ['9a65d55876240889e2df4b4fab3263f0' => 'publish'],
        48 => ['aeb9c86fe4c92d0f21c36b82cb79fe87' => 'publish'],
        49 => ['b0cbd74b91403fdfa743eca3e84cd68c' => 'publish'],
        50 => ['17eb45cc3d02e6f41a0edbe43f28e99d' => 'publish'],
        51 => ['794d8e7b0ef8f3eea59e04c8d940f7a2' => 'publish'],
        52 => ['1bc4fa3fea1a50e5aa28685b8609e92c' => 'publish'],
        53 => ['4ff3dad4323146b4fce4ca8a487e7568' => 'publish'],
        54 => ['e2eddee6c11b668a91a11a2f5161cd0c' => 'publish'],
        55 => ['08ee9f953dcf5580af4f289927bf031e' => 'publish'],
        56 => ['10159f3d8b09dc60cc2b784a3595f7e1' => 'publish'],
        57 => ['8a87422adbd4bb700995c67f04fa3f94' => 'publish'],
        58 => ['c9eb9f2da4578a8d10129e8b15ede1f7' => 'publish'],
        59 => ['199a9442562fd6a4c2417e803a191b75' => 'publish'],
        60 => ['d3746881581c375da519361a046fbeb0' => 'publish'],
        61 => ['8bd44aa6e53efa18f9ad6ab392d84d31' => 'publish'],
        62 => ['7afd7be545710e55f6fc69ab111bc032' => 'publish'],
        63 => ['8b37068d33fcb138ed1f8fcd4086ca8e' => 'publish'],
        64 => ['17b75d4ec9efdea9dae08206ce70bd1f' => 'publish'],
        65 => ['669bc2561d3447cdc96327479bd87bb2' => 'publish'],
        66 => ['f083d406253701f6dfc60ff2c1981e2f' => 'publish'],
        67 => ['cd60f70d70e9b47decf6f97781689b70' => 'publish'],
        68 => ['266e31d7ffdf789eafdcbaeb362e6307' => 'publish'],
        69 => ['825c9628a08ee41d5904cb78fbca9460' => 'publish'],
        70 => ['93bd6b8af0e3883d63a87e6ea88cae19' => 'publish'],
        71 => ['57d6d176e8cf1297f5b2ad0f003d6019' => 'publish'],
        72 => ['b6d96d059b2a90116e3018958793ce2d' => 'publish'],
        73 => ['a4b93c698f0c04f4dde833fa84251535' => 'publish'],
        74 => ['3a6bd2b1982b63237f0ce5b487c418e7' => 'publish'],
        75 => ['10836bfdd8181e2009928b99585496a6' => 'publish'],
        76 => ['397208e693b0bf12de2a143ba45d0f44' => 'publish'],
        77 => ['1f3f82b7b5b7ce59932b5d82089e57fe' => 'publish'],
        78 => ['e27973da521d2aad328c3aa4eff7980f' => 'publish'],
        79 => ['89be89f156d7cc0b8d34031d2fa311cf' => 'publish'],
        80 => ['a3c99267919003cefa0ea44f313dc0f2' => 'publish'],
        81 => ['bdf07991c9bd9827d8e986a21f6ff2cc' => 'publish'],
        82 => ['dcdb5182e8722c8a36de7c4b30e94490' => 'publish'],
        83 => ['ad877b21190d040f7b1ed8215da5dcaa' => 'publish'],
        84 => ['c10d13b2359c4868f1e4687996d073cb' => 'publish'],
        85 => ['cbfb403705a7d0582f63bedc6bed2efb' => 'publish', '622df6fc36809c72e885a97991bd7a7c' => 'publish'],
        86 => ['124c515cd271fdee14c5b80f66d64ce3' => 'publish'],
        87 => ['e127ae09e0f130d5f66c96096bc12578' => 'publish'],
        88 => ['016e006c64afb02f4841f6413727f7a8' => 'publish'],
        89 => ['648ae194d320586f85c65f7dc028d583' => 'publish'],
        90 => ['18864f6fc3d277b0bcea31bc65dccfe3' => 'publish'],
        91 => ['6f7a6eb653e28fa1decc32dd53079729' => 'publish'],
        92 => ['8be4d1be289e0ed9f047862c8343dd86' => 'publish'],
    ];
}
