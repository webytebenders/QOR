<?php
/**
 * Chatbot Knowledge Base — DB-driven with PHP fallback
 * Improved fuzzy matching with synonym support
 */

// Synonym map for better matching
function getSynonyms(): array {
    return [
        'wallet' => ['wallets', 'account', 'accounts'],
        'biometric' => ['biometrics', 'face', 'fingerprint', 'face id', 'touch id', 'scan', 'facial'],
        'token' => ['tokens', '$qor', 'qor', 'coin', 'coins', 'crypto'],
        'security' => ['secure', 'safe', 'safety', 'protection', 'protect'],
        'staking' => ['stake', 'staked', 'yield', 'earn', 'rewards', 'apy', 'interest'],
        'price' => ['cost', 'buy', 'invest', 'exchange', 'listing', 'purchase'],
        'help' => ['support', 'contact', 'reach', 'assistance'],
        'recovery' => ['recover', 'lost', 'backup', 'restore'],
    ];
}

function expandWithSynonyms(string $message): string {
    $expanded = $message;
    foreach (getSynonyms() as $root => $syns) {
        foreach ($syns as $syn) {
            if (str_contains($message, $syn)) {
                $expanded .= ' ' . $root;
                break;
            }
        }
    }
    return $expanded;
}

function getHardcodedKnowledge(): array {
    return [
        [
            'keywords' => ['biometric', 'face', 'fingerprint', 'face id', 'touch id', 'scan'],
            'response' => "Core Chain uses your device's biometric authentication (Face ID / Touch ID / Fingerprint) as your wallet key. Your face or fingerprint is your key. Your biometric data is processed entirely inside your phone's Secure Enclave chip — it never leaves your device, never touches the blockchain, and is never stored by Core Chain. One scan = full access to your sovereign wallet.",
            'quick_replies' => ['How is my data kept private?', 'What if I lose my phone?']
        ],
        [
            'keywords' => ['seed phrase', 'seed', '12 words', 'recovery phrase', 'mnemonic'],
            'response' => "Core Chain eliminates seed phrases entirely. Over \$200B in crypto has been permanently lost to forgotten seed phrases. With Core Chain, your face or fingerprint is your key — no 12 words to memorize, no paper backups to lose. Your private key lives in your device's Secure Enclave hardware chip.",
            'quick_replies' => ['How does biometric auth work?', 'What about device recovery?']
        ],
        [
            'keywords' => ['token', '$qor', 'qor token', 'tokenomics', 'supply'],
            'response' => "\$QOR has a fixed supply of 250 million tokens — no more will ever be created. The token powers the ecosystem through:\n\n• **Staking** — Earn real yield from merchant processing fees\n• **Node Bonding** — Relayers lock 100K QOR per node\n• **Buyback & Burn** — 1% merchant fee drives automatic deflation\n• **Governance** — Token holders vote on protocol upgrades\n\nValue comes from GDP, not inflation.",
            'quick_replies' => ['Tell me about staking', 'When does the token launch?']
        ],
        [
            'keywords' => ['security', 'secure', 'safe', 'vault', 'duress', 'hack'],
            'response' => "Core Chain has 4 layers of security:\n\n1. **Secure Enclave** — Private keys in hardware, never extractable\n2. **Biometric Auth** — Your face/fingerprint is the only key\n3. **On-Chain Verification** — Solana SIMD-0075 native biometric verification\n4. **ZK Proofs** — Identity verification without exposing data\n\nPlus physical defense: Vault Mode (48hr timelock), Duress PIN (decoy wallet), and Dead Man's Switch for estate planning.",
            'quick_replies' => ['What is ZK compression?', 'How does recovery work?']
        ],
        [
            'keywords' => ['zk', 'zero knowledge', 'privacy', 'compliance', 'kyc', 'aml'],
            'response' => "Core Chain uses Zero-Knowledge Compression for privacy-preserving compliance. Rich personal data enters the ZK Prover — only a 128-byte Merkle Root comes out on-chain. Banks can verify your identity, but the world sees only math. We verify identity for the bank, but publish only math to the world.",
            'quick_replies' => ['Tell me about ISO 20022', 'How does cross-chain work?']
        ],
        [
            'keywords' => ['staking', 'yield', 'earn', 'rewards', 'apy'],
            'response' => "Core Chain staking is powered by real merchant revenue, not token inflation. When merchants process payments, 0.1% goes to stakers as yield. This means:\n\n• **Non-dilutive** — No new tokens minted for rewards\n• **Revenue-backed** — More volume = more yield\n• **Compounding** — As burns reduce supply, your share grows\n\nStake via the veQOR contract for up to 300% yield multiplier.",
            'quick_replies' => ['What is the $QOR token?', 'How do nodes work?']
        ],
        [
            'keywords' => ['solana', 'simd', 'blockchain', 'chain', 'network'],
            'response' => "Core Chain is built natively on Solana, leveraging SIMD-0075 for native biometric signature verification. Why Solana?\n\n• **400ms finality** (vs 12 min on Ethereum)\n• **Negligible gas fees**\n• **Native account abstraction** (no alt-mempools)\n• **Unified state** (no bundlers needed)\n\nPlus the EVM Escape Hatch gives cross-chain access to Ethereum, Base, BSC, and more.",
            'quick_replies' => ['How does cross-chain work?', 'What about security?']
        ],
        [
            'keywords' => ['cross chain', 'evm', 'ethereum', 'bridge', 'multi chain'],
            'response' => "Core Chain's EVM Controller Model lets you use one biometric identity across every blockchain. Solana is the Master Controller, with satellite wallets on Ethereum, Base, BSC, and Avalanche. Trustless light client state proofs (PLONK ZK-SNARKs) replace vulnerable multi-sig bridges. One identity, every chain, no bridge risk.",
            'quick_replies' => ['Tell me about Solana', 'What is ZK compression?']
        ],
        [
            'keywords' => ['iso', '20022', 'bank', 'institution', 'regulatory'],
            'response' => "Core Chain natively maps Solana transactions to ISO 20022 (pacs.008) format — the global banking standard replacing SWIFT. Transfer Hooks enforce real-time AML screening via Regulatory Oracles. If a wallet is flagged, the transaction atomically reverts. Banks get compliance certainty, users keep privacy.",
            'quick_replies' => ['What is ZK compression?', 'Tell me about security']
        ],
        [
            'keywords' => ['whitepaper', 'paper', 'documentation', 'docs'],
            'response' => "You can read the full Core Chain whitepaper here: [Whitepaper QOR.pdf](Whitepaper%20QOR.pdf)\n\nIt covers the complete technical architecture — biometric cryptography, account abstraction, fee mechanics, ZK compliance, cross-chain interoperability, defensive security, and tokenomics.",
            'quick_replies' => ['What is the $QOR token?', 'How does biometric auth work?']
        ],
        [
            'keywords' => ['launch', 'when', 'date', 'timeline', 'roadmap'],
            'response' => "Core Chain is targeting a 2026 launch. Join the waitlist on our homepage to get:\n\n• Early access to the biometric wallet\n• Development milestone updates\n• Priority access to the token launch\n\nWe'll keep you updated every step of the way.",
            'quick_replies' => ['What is the $QOR token?', 'How do I contact the team?']
        ],
        [
            'keywords' => ['bonding', 'node', 'relayer', 'operator', 'infrastructure'],
            'response' => "To operate a Core Chain relayer node, operators must bond 100,000 QOR tokens. With a target of 500 enterprise nodes, that's 20% of total supply permanently locked. Nodes earn junction fees (0.6% of transactions in USDC). More volume = more demand for nodes = more buy pressure on QOR.",
            'quick_replies' => ['Tell me about staking', 'What is the $QOR token?']
        ],
        [
            'keywords' => ['lost device', 'phone lost', 'recovery', 'dead man'],
            'response' => "If your device is lost:\n\n1. **Multi-device sync** — Register biometrics on a backup device beforehand\n2. **Social recovery** — Trusted contacts initiate time-delayed recovery\n3. **Dead Man's Switch** — If no biometric check-in for X days, funds auto-transfer to your designated beneficiaries\n\nNo seed phrase needed for any recovery path.",
            'quick_replies' => ['How does biometric auth work?', 'Tell me about security']
        ],
        [
            'keywords' => ['contact', 'support', 'help', 'email', 'reach'],
            'response' => "You can reach the Core Chain team at:\n\n• **Email:** hello@corechain.io\n• **Twitter/X:** [@QOR_network](https://x.com/QOR_network)\n• **Telegram:** [t.me/QOR_Networks](https://t.me/QOR_Networks)\n\nOr use the contact form on our website — we respond within 48 hours.",
            'quick_replies' => ['Join the waitlist', 'Read the whitepaper']
        ],
        [
            'keywords' => ['price', 'buy', 'invest', 'exchange', 'listing'],
            'response' => "The QOR token hasn't launched yet — we're targeting 2026. Join the waitlist to be first in line. We don't provide price predictions or investment advice. The token's value is designed to be driven by real merchant transaction volume through the buyback & burn mechanism.",
            'quick_replies' => ['What is the $QOR token?', 'When is the launch?']
        ],
    ];
}

/**
 * Find best bot response — checks DB knowledge first, then hardcoded fallback
 */
function findBotResponse(string $userMessage, ?PDO $db = null): ?string {
    $message = strtolower(trim($userMessage));
    $expanded = expandWithSynonyms($message);

    $bestMatch = null;
    $bestScore = 0;
    $bestId = null;
    $quickReplies = null;

    // 1. Check DB knowledge base first
    if ($db) {
        try {
            $rows = $db->query('SELECT * FROM chat_knowledge WHERE is_active = 1')->fetchAll();
            foreach ($rows as $row) {
                $keywords = array_map('trim', explode(',', strtolower($row['keywords'])));
                $score = 0;
                foreach ($keywords as $keyword) {
                    if ($keyword && str_contains($expanded, $keyword)) {
                        $score += strlen($keyword);
                    }
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestMatch = $row['response'];
                    $bestId = $row['id'];
                    $quickReplies = $row['quick_replies'];
                }
            }
        } catch (Exception $e) {
            // Table may not exist yet
        }
    }

    // 2. Fall back to hardcoded knowledge
    foreach (getHardcodedKnowledge() as $entry) {
        $score = 0;
        foreach ($entry['keywords'] as $keyword) {
            if (str_contains($expanded, strtolower($keyword))) {
                $score += strlen($keyword);
            }
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestMatch = $entry['response'];
            $bestId = null;
            $quickReplies = json_encode($entry['quick_replies'] ?? []);
        }
    }

    // Update hit count for DB entries
    if ($bestId && $db) {
        try {
            $db->prepare('UPDATE chat_knowledge SET hit_count = hit_count + 1 WHERE id = ?')->execute([$bestId]);
        } catch (Exception $e) {}
    }

    // Log unanswered if no match
    if ($bestScore < 3 && $db) {
        try {
            $db->prepare('INSERT INTO chat_unanswered (message) VALUES (?)')->execute([$userMessage]);
        } catch (Exception $e) {}
    }

    // Return match with quick replies
    if ($bestScore >= 3 && $bestMatch) {
        $GLOBALS['_chatbot_quick_replies'] = json_decode($quickReplies ?: '[]', true) ?: [];
        return $bestMatch;
    }

    return null;
}

/**
 * Seed DB knowledge from hardcoded entries (run once)
 */
function seedKnowledge(PDO $db): int {
    $count = $db->query('SELECT COUNT(*) FROM chat_knowledge')->fetchColumn();
    if ($count > 0) return 0;

    $seeded = 0;
    foreach (getHardcodedKnowledge() as $entry) {
        $keywords = implode(', ', $entry['keywords']);
        $qr = json_encode($entry['quick_replies'] ?? []);
        $db->prepare('INSERT INTO chat_knowledge (category, keywords, response, quick_replies) VALUES (?, ?, ?, ?)')
            ->execute(['General', $keywords, $entry['response'], $qr]);
        $seeded++;
    }
    return $seeded;
}
