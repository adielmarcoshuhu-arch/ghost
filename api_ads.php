<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Parâmetros da requisição
$search_term = $_GET['search_term'] ?? '';
$country = $_GET['country'] ?? 'BR';
$date_min = $_GET['date_min'] ?? '';
$date_max = $_GET['date_max'] ?? '';
$platforms = $_GET['platforms'] ?? '';
$language = $_GET['language'] ?? 'pt';
$technology = $_GET['technology'] ?? '';
$checkout = $_GET['checkout'] ?? '';
$player = $_GET['player'] ?? '';
$button = $_GET['button'] ?? '';
$media_type = $_GET['media_type'] ?? '';
$curtidas_min = intval($_GET['curtidas_min'] ?? 0);
$compartilhamentos_min = intval($_GET['compartilhamentos_min'] ?? 0);
$comentarios_min = intval($_GET['comentarios_min'] ?? 0);
$hot_order = $_GET['hot_order'] ?? 'recent';
$hot_period_min = intval($_GET['hot_period_min'] ?? 10);
$ads_per_creative = intval($_GET['ads_per_creative'] ?? 5);

// Coloque seu token de acesso do Facebook aqui (obtenha em https://developers.facebook.com/tools/explorer/)
$access_token = 'EAALiyXGLdpYBPrjybz8wyd4B14qhzKNrpx4RGDloAYtXKyxYRdEIez398NILtsdt7FZCkV9Nocqw60tnhtxTzqKtXhZAq31gE7xvaiW0hbIFeXGdBLJNGk8bIZCEOglumClK9IgOcZBqbC18Mdd092DtdjwYRal6hWdKaHVGPNMeTU6ppagQ4uW5YZBiVw8tK';

// Construir parâmetros para a API do Facebooka
$api_params = [
    'search_terms' => $search_term,
    'ad_reached_countries' => [$country],
    'fields' => 'id,page_name,ad_creative_body,ad_snapshot_url,ad_creative_link_description,ad_delivery_start_time,ad_delivery_stop_time,ad_creative_call_to_action_type',
    'limit' => 50,
    'access_token' => $access_token
];

// Adicionar filtros de data se fornecidos
if ($date_min) { 
    $api_params['ad_delivery_date_min'] = date('Y-m-d', strtotime($date_min));
}
if ($date_max) {
    $api_params['ad_delivery_date_max'] = date('Y-m-d', strtotime($date_max));
}

// Verificar se há termo de busca
if (!$search_term) {
    $mock_ads = generateMockAds($search_term);
    echo json_encode(['data' => $mock_ads, 'source' => 'mock']);
    exit;
}

// Construir URL da API
$api_url = 'https://graph.facebook.com/v18.0/ads_archive?' . http_build_query($api_params);

// Fazer requisição para a API
$context = stream_context_create([
    'http' => [
        'timeout' => 30,
        'user_agent' => 'Mozilla/5.0 (compatible; Ghost IA Bot)'
    ]
]);

$response = @file_get_contents($api_url, false, $context);

if ($response === false) {
    // Fallback com dados mock se a API falhar
    $mock_ads = generateMockAds($search_term);
    echo json_encode(['data' => $mock_ads, 'source' => 'mock']);
    exit;
}

$data = json_decode($response, true);

if (!$data || !isset($data['data'])) {
    $mock_ads = generateMockAds($search_term);
    echo json_encode(['data' => $mock_ads, 'source' => 'mock']);
    exit;
}

$ads = $data['data'];
$filtered_ads = [];

foreach ($ads as $ad) {
    // Aplicar filtros adicionais
    if (!passesFilters($ad, $platforms, $language, $technology, $checkout, $player, $button, $media_type, $hot_period_min)) {
        continue;
    }
    
    // Enriquecer dados do anúncio
    $enriched_ad = enrichAdData($ad);
    $filtered_ads[] = $enriched_ad;
}

// Ordenar por data se necessário
if ($hot_order === 'recent') {
    usort($filtered_ads, function($a, $b) {
        return strtotime($b['ad_delivery_start_time']) - strtotime($a['ad_delivery_start_time']);
    });
}

// Agrupar por criativo e limitar
$grouped_ads = groupAdsByCreative($filtered_ads, $ads_per_creative);

// Limitar resultado final
$final_ads = array_slice($grouped_ads, 0, 20);

echo json_encode(['data' => $final_ads, 'source' => 'api']);

function passesFilters($ad, $platforms, $language, $technology, $checkout, $player, $button, $media_type, $hot_period_min) {
    // Filtro de plataformas
    if ($platforms) {
        $platform_list = explode(',', strtolower($platforms));
        $ad_platforms = array_map('strtolower', $ad['platforms'] ?? []);
        if (!array_intersect($platform_list, $ad_platforms)) {
            return false;
        }
    }
    
    // Filtro de idioma
    if ($language && isset($ad['ad_creative_body'])) {
        $body = strtolower($ad['ad_creative_body']);
        if ($language === 'pt' && !preg_match('/[àáâãäéêëíîïóôõöúûüç]/u', $body)) {
            // Verificação básica para português
            if (!stripos($body, 'você') && !stripos($body, 'não') && !stripos($body, 'com')) {
                return false;
            }
        }
    }
    
    // Filtros de tecnologia, checkout, player
    $searchable_text = ($ad['ad_creative_body'] ?? '') . ' ' . ($ad['ad_creative_link_description'] ?? '');
    
    if ($technology && !stripos($searchable_text, $technology)) {
        return false;
    }
    
    if ($checkout && !stripos($searchable_text, $checkout)) {
        return false;
    }
    
    if ($player && !stripos($searchable_text, $player)) {
        return false;
    }
    
    // Filtro de botão
    if ($button && isset($ad['ad_creative_call_to_action_type'])) {
        $cta_map = [
            'SHOP_NOW' => 'comprar',
            'LEARN_MORE' => 'saiba mais',
            'SIGN_UP' => 'cadastrar'
        ];
        
        if (!stripos($cta_map[$ad['ad_creative_call_to_action_type']] ?? '', $button)) {
            return false;
        }
    }
    
    // Filtro de tipo de mídia
    if ($media_type && isset($ad['ad_snapshot_url'])) {
        $url = $ad['ad_snapshot_url'];
        if ($media_type === 'image' && !preg_match('/\.(jpg|jpeg|png|gif)$/i', $url)) {
            return false;
        }
        if ($media_type === 'video' && !preg_match('/\.(mp4|mov|avi)$/i', $url)) {
            return false;
        }
    }
    
    // Filtro de período ativo
    if ($hot_period_min > 0 && isset($ad['ad_delivery_start_time'])) {
        $start_date = strtotime($ad['ad_delivery_start_time']);
        $days_active = (time() - $start_date) / (24 * 60 * 60);
        if ($days_active < $hot_period_min) {
            return false;
        }
    }
    
    return true;
}

function enrichAdData($ad) {
    // Adicionar dados calculados e mock
    $ad['creative_count'] = rand(1, 8);
    $ad['likes'] = rand(100, 2500);
    $ad['shares'] = rand(10, 300);
    $ad['comments'] = rand(5, 150);
    $ad['hours_ago'] = rand(1, 48);
    $ad['days_active'] = rand(5, 45);
    
    // Determinar plataformas com ícones
    $platform_icons = [];
    if (isset($ad['platforms'])) {
        foreach ($ad['platforms'] as $platform) {
            switch (strtolower($platform)) {
                case 'facebook':
                    $platform_icons[] = 'F';
                    break;
                case 'instagram':
                    $platform_icons[] = 'I';
                    break;
                case 'messenger':
                    $platform_icons[] = 'M';
                    break;
            }
        }
    }
    $ad['platform_icons'] = $platform_icons;
    
    // Formatar data
    if (isset($ad['ad_delivery_start_time'])) {
        $ad['formatted_date'] = date('d M Y', strtotime($ad['ad_delivery_start_time']));
    }
    
    return $ad;
}

function groupAdsByCreative($ads, $ads_per_creative) {
    $groups = [];
    
    foreach ($ads as $ad) {
        $creative_hash = md5(($ad['page_name'] ?? '') . ($ad['ad_creative_body'] ?? ''));
        
        if (!isset($groups[$creative_hash])) {
            $groups[$creative_hash] = [];
        }
        
        if (count($groups[$creative_hash]) < $ads_per_creative) {
            $groups[$creative_hash][] = $ad;
        }
    }
    
    // Mesclar grupos de volta em array plano
    $result = [];
    foreach ($groups as $group) {
        $result = array_merge($result, $group);
    }
    
    return $result;
}

function generateMockAds($search_term) {
    $mock_ads = [
        [
            'id' => '1234567890',
            'page_name' => 'Curso Marketing Digital Pro',
            'ad_creative_body' => 'Descubra os segredos do marketing digital que estão gerando R$ 10.000/mês para nossos alunos. Método comprovado e resultados garantidos!',
            'ad_snapshot_url' => 'https://images.pexels.com/photos/267350/pexels-photo-267350.jpeg?auto=compress&cs=tinysrgb&w=400',
            'ad_creative_link_description' => 'Acesse agora e transforme sua vida financeira',
            'ad_delivery_start_time' => date('Y-m-d', strtotime('-15 days')),
            'platforms' => ['Facebook', 'Instagram'],
            'ad_creative_call_to_action_type' => 'LEARN_MORE',
            'creative_count' => 5,
            'likes' => 1250,
            'shares' => 89,
            'comments' => 156,
            'hours_ago' => 12,
            'days_active' => 15,
            'platform_icons' => ['F', 'I'],
            'formatted_date' => date('d M Y', strtotime('-15 days'))
        ],
        [
            'id' => '2345678901',
            'page_name' => 'Dropshipping Experts',
            'ad_creative_body' => 'Monte sua loja virtual em 7 dias e comece a vender produtos importados com margem de 300%. Sem estoque, sem complicação!',
            'ad_snapshot_url' => 'https://images.pexels.com/photos/230544/pexels-photo-230544.jpeg?auto=compress&cs=tinysrgb&w=400',
            'ad_creative_link_description' => 'Comece seu negócio online hoje mesmo',
            'ad_delivery_start_time' => date('Y-m-d', strtotime('-8 days')),
            'platforms' => ['Facebook'],
            'ad_creative_call_to_action_type' => 'SHOP_NOW',
            'creative_count' => 3,
            'likes' => 890,
            'shares' => 45,
            'comments' => 78,
            'hours_ago' => 6,
            'days_active' => 8,
            'platform_icons' => ['F'],
            'formatted_date' => date('d M Y', strtotime('-8 days'))
        ],
        [
            'id' => '3456789012',
            'page_name' => 'Investimentos Inteligentes',
            'ad_creative_body' => 'Aprenda a investir na bolsa de valores com segurança e multiplicar seu dinheiro. Método usado por investidores profissionais.',
            'ad_snapshot_url' => 'https://images.pexels.com/photos/590022/pexels-photo-590022.jpeg?auto=compress&cs=tinysrgb&w=400',
            'ad_creative_link_description' => 'Acesso exclusivo por tempo limitado',
            'ad_delivery_start_time' => date('Y-m-d', strtotime('-22 days')),
            'platforms' => ['Instagram'],
            'ad_creative_call_to_action_type' => 'SIGN_UP',
            'creative_count' => 1,
            'likes' => 2100,
            'shares' => 234,
            'comments' => 445,
            'hours_ago' => 3,
            'days_active' => 22,
            'platform_icons' => ['I'],
            'formatted_date' => date('d M Y', strtotime('-22 days'))
        ]
    ];
    
    return $mock_ads;
}
?>