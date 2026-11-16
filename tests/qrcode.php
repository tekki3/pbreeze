<?php
declare(strict_types=1);

function qrSvgModules(string $svg): string
{
    $document = new DOMDocument();
    check($document->loadXML($svg, LIBXML_NONET));
    $edge = (int) explode(' ', $document->documentElement->getAttribute('viewBox'))[2];
    $rows = array_fill(0, $edge, str_repeat('0', $edge));
    foreach ($document->getElementsByTagName('path') as $path) {
        preg_match_all('/M(\d+),(\d+)h(\d+)v1h-\d+z/', $path->getAttribute('d'), $runs, PREG_SET_ORDER);
        foreach ($runs as [, $x, $y, $length]) {
            $rows[(int) $y] = substr_replace($rows[(int) $y], str_repeat('1', (int) $length), (int) $x, (int) $length);
        }
    }
    return implode('', $rows);
}

test('Native QR matrices match independent byte-mode reference vectors through version 40', static function (): void {
    // libqrencode level-L reference matrices, accepting any of its eight masks.
    $vectors = [
        17 => [
            'c36109dfda50e88adb994ab79e3419c60cb6898126518046c3569b0f038febde',
            '250980f07bc56f9aa448b280e3eb3cdc61b5c17d68d106560ca26c9cadea52bb',
            '28c9ef9e5490aae8af700461592a6bc402d8ccd4f02c620d1091010627471b48',
            'c70ec65120499995c057bd7f281cf96f50f236e953567fdf9a39eae8231d206b',
            'a2a1db77680755f8b356969940fe90e4e9e1c76f117a80654c245e20474a2b86',
            'daaa53521eba2b0b7c9ab2cb5d676f3158ad0a98bb00ca90cf58cbbef9d67567',
            'f88b9320ea487477ad8900519ef0ed57ee8cbdd7a2378926f0a07b21618ea191',
            '37bf7034e0fa8eff4673e27d8b00419775fd82ce2cf0f89af9d3296f448b8fd4',
        ],
        154 => [
            'fcf72d8f107469eaa7cfd1b8768ebdc022abddedc7671318be8cda5b6dea34b3',
            'a475b40490c7ef25612369c387d743ca8d2b377e52c24fed3b48958a695b1874',
            '051e8a3dfe17f3f1a989b51c2a7cb6c9d0459635e07030eb4ea6fb3ab721da14',
            'b2273eae8f64ff526207f5e9348a35eac95889a5f9d0344d63f216c4b3666450',
            'b8f601758e88dfa85971c9e51986ca748c4d6852b760ec2b6a192f2ea0c7e387',
            '6f606b9d7481540662ed71e82f88eea58917f6ee623d42f66eb53755bff7631e',
            'ddec754f58f5c0377d4b20a522c478a75b96aa18694056611d935dc2404b58a2',
            '1a378b989798d5b131e1c259d8e3c3194ea4be2a9f8c51445fc87fe37a84cdd2',
        ],
        271 => [
            '20791ad5a1483557fce445a50fdef3e22cbf9fed54457c907247f745f3b281eb',
            '370dfdc72680c998123a667b43d563458d729d05d6224d0b13c589791d49bfac',
            'e8dd94264ea0979ef6f14c444f148e866dc787982b84745ba2834d22ab18622e',
            '307b8edaabc96346828465975fa3f4377a82e9e58a8d587800b15327e302a75a',
            '17788ddfefc053eb59b35b1ad514f47ae7638d5c91bd3aa3dea23fb99d934bff',
            '7a09fe8dd97a940e09cb7d87551fef28177159a3272b711ec098d620ffc7776a',
            '033742994dc58611664a3308edac0fcd1e64ec3cd45f3c6639df1a1bd7e342ff',
            'ec3fdf422e59a9cc03e6b17f7871eabce701a7d55a901b41d46b7b2af3d77f88',
        ],
        1952 => [
            'f216690161ab2a96bd703f9a31b5a6f8a0b2bf1dd315c79fe45ac3f8dd294dbc',
            '50f8787e99431b082e5ec321f4128f88420ecae878708ee79da0461136467347',
            '7db8714d43369ea8e4d79db548ab09891ea2010960acde5d0d8ff4fb4e10da3b',
            '56c1ddadcc3068531b998c47a4d06eff606d9cc40bb1b3a88242401c8c537e8c',
            '74c26c5bc5ee29352ecf9d713759bdde4cf5b9fa414b42a1885a8ad4230deb80',
            '35c6d343e90de286de3e136815469494e30953adea18eed938a1128aefa5c0b0',
            '21817b2b0568679c77d67435c57e02531d593f11098f252d348347782267b4ef',
            'ee921be55580ca4280b15fe34c75a25dc81d840df82b7cbfc3aa3fd4de9b8052',
        ],
        2953 => [
            '716de1c29ef8fc2539d09b0ac2b2217b6ee88ea4ae37ed4c4bf3e9c1a01cf132',
            '099846310bb51495a838f80bf96fc47195669a0993f363932cad1cc6094f92d3',
            'bf3f67cec426c4e04fc915dfb58b34331c3396041461ed073c45a15b266e2ffb',
            'adce6afc4915b993f8c789e641914dd77b6846b7feef58d803d37ff41a22ff21',
            '641827f9ca9ddc3dcdb0bf7e9b822b5506513c45d010273dc4a36938b7a90ef4',
            '62a1f4ea8093cedb00b6278e780072873b9b36a1f7a679f69f2576dc5135fd63',
            '65a7711ceaf11fb924fbf71f64519b856e663a06085a756708a2b962bac30dec',
            'f285efdce2fe806f1d5f80c8ef1c8df24d6b0fa1a942323fa43d5d0fe50d8957',
        ],
    ];
    foreach ($vectors as $length => $hashes) {
        $modules = qrSvgModules(PBreeze\QrCode::svg(str_repeat('a', $length)));
        check(in_array(hash('sha256', $modules), $hashes, true), "QR reference mismatch for $length bytes.");
    }
});

test('QR input bounds are explicit and payload markup cannot become active SVG content', static function (): void {
    foreach (['', str_repeat('a', 2954), "\0", "line\nbreak", "\xc3\xa9"] as $invalid) {
        raises(fn () => PBreeze\QrCode::svg($invalid), InvalidArgumentException::class);
    }
    $svg = PBreeze\QrCode::svg('<script>alert("secret")</script>');
    $document = new DOMDocument();
    check($document->loadXML($svg, LIBXML_NONET));
    same(0, $document->getElementsByTagName('script')->length);
    check(!str_contains($svg, 'secret'));
    same(3, $document->getElementsByTagName('*')->length);
});
