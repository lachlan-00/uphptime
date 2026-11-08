<?php

declare(strict_types=1);

/**
 * vim:set softtabstop=4 shiftwidth=4 expandtab:
 *
 * LICENSE: GNU Affero General Public License, version 3 (AGPL-3.0-or-later)
 * Copyright Lachlan de Waard <lachlan.00@gmail.com>, 2026
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

use function DI\autowire;
use function DI\factory;

use Psr\Container\ContainerInterface;
use Uphptime\Api\ChallengeToken\ChallengeTokenStoreInterface;
use Uphptime\Api\Client\PeerCheckClientInterface;
use Uphptime\Check\AttestationVerifier;
use Uphptime\Check\CanarySampler;
use Uphptime\Check\CheckRunner;
use Uphptime\Check\DownConfirmer;
use Uphptime\Check\Http\CurlHttpClient;
use Uphptime\Check\Http\HttpChecker;
use Uphptime\Check\Http\HttpKeywordChecker;
use Uphptime\Check\Icmp\IcmpChecker;
use Uphptime\Check\Icmp\SystemPingClient;
use Uphptime\Check\PeerResultAcquirer;
use Uphptime\Check\Tcp\StreamTcpClient;
use Uphptime\Check\Tcp\TcpChecker;
use Uphptime\Config\Config;
use Uphptime\Peer\PeerReputationScorer;
use Uphptime\Peer\PeerSelectorInterface;
use Uphptime\Reporting\RetentionPruner;
use Uphptime\Reporting\RollupBuilder;
use Uphptime\Reporting\UptimeReportCalculator;
use Uphptime\Site\SiteStateMachine;
use Uphptime\Storage\HistoryRepositoryInterface;
use Uphptime\Storage\PeerReputationRepositoryInterface;
use Uphptime\Storage\SiteRepositoryInterface;
use Uphptime\Support\ClockInterface;
use Uphptime\Support\NetworkStackInterface;
use Uphptime\Support\RandomInterface;

// The self-check (owner) side only; the pinger side wires HttpChecker/HttpKeywordChecker/TcpChecker
// itself with Safe* clients per request, inside Pinger\DelegatedCheckExecutor.
return [
    AttestationVerifier::class => autowire(),
    CanarySampler::class => factory(static function (ContainerInterface $c): CanarySampler {
        $config = $c->get(Config::class);

        return new CanarySampler(
            $c->get(RandomInterface::class),
            $c->get(PeerSelectorInterface::class),
            $c->get(PeerResultAcquirer::class),
            $c->get(PeerReputationRepositoryInterface::class),
            $c->get(PeerReputationScorer::class),
            $c->get(ClockInterface::class),
            $config,
            [
                'http' => $c->get('check.self.http_checker'),
                'http_keyword' => $c->get('check.self.http_keyword_checker'),
                'tcp' => $c->get('check.self.tcp_checker'),
                'icmp' => $c->get('check.self.icmp_checker'),
            ],
            $config->canarySampleRate,
        );
    }),
    CheckRunner::class => factory(static function (ContainerInterface $c): CheckRunner {
        return new CheckRunner(
            $c->get(SiteRepositoryInterface::class),
            $c->get(HistoryRepositoryInterface::class),
            $c->get(SiteStateMachine::class),
            $c->get(RollupBuilder::class),
            $c->get(RetentionPruner::class),
            $c->get(ClockInterface::class),
            $c->get(Config::class),
            $c->get(ChallengeTokenStoreInterface::class),
            $c->get('check.self.http_checker'),
            $c->get('check.self.http_keyword_checker'),
            $c->get('check.self.tcp_checker'),
            $c->get('check.self.icmp_checker'),
            $c->get(PeerSelectorInterface::class),
            $c->get(PeerResultAcquirer::class),
            $c->get(DownConfirmer::class),
            $c->get(CanarySampler::class),
        );
    }),
    DownConfirmer::class => factory(static function (ContainerInterface $c): DownConfirmer {
        $config = $c->get(Config::class);

        return new DownConfirmer(
            $c->get(PeerSelectorInterface::class),
            $c->get(PeerCheckClientInterface::class),
            $c->get(PeerReputationRepositoryInterface::class),
            $c->get(PeerReputationScorer::class),
            $c->get(ClockInterface::class),
            $config,
            $c->get(AttestationVerifier::class),
            [
                'http' => $c->get('check.self.http_checker'),
                'http_keyword' => $c->get('check.self.http_keyword_checker'),
                'tcp' => $c->get('check.self.tcp_checker'),
                'icmp' => $c->get('check.self.icmp_checker'),
            ],
            $config->maxDelegatesPerAcquisition,
        );
    }),
    PeerResultAcquirer::class => factory(static function (ContainerInterface $c): PeerResultAcquirer {
        return new PeerResultAcquirer(
            $c->get(PeerSelectorInterface::class),
            $c->get(PeerCheckClientInterface::class),
            $c->get(PeerReputationRepositoryInterface::class),
            $c->get(PeerReputationScorer::class),
            $c->get(ClockInterface::class),
            $c->get(AttestationVerifier::class),
            $c->get(Config::class)->maxDelegatesPerAcquisition,
        );
    }),
    RetentionPruner::class => autowire(),
    RollupBuilder::class => autowire(),
    SiteStateMachine::class => autowire(),
    UptimeReportCalculator::class => autowire(),
    'check.self.http_checker' => factory(
        static fn(ContainerInterface $c) => new HttpChecker(new CurlHttpClient($c->get(NetworkStackInterface::class)), $c->get(Config::class)->httpUserAgent),
    ),
    'check.self.http_keyword_checker' => factory(
        static fn(ContainerInterface $c) => new HttpKeywordChecker(new CurlHttpClient($c->get(NetworkStackInterface::class)), $c->get(Config::class)->httpUserAgent),
    ),
    'check.self.tcp_checker' => factory(static fn() => new TcpChecker(new StreamTcpClient())),
    'check.self.icmp_checker' => factory(static fn() => new IcmpChecker(new SystemPingClient())),
];
