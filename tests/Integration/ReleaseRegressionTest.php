<?php

namespace Vampires\Sentinels\Tests\Integration;

use Vampires\Sentinels\Agents\BaseAgent;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Support\Validation\ValidatesOrder;
use Vampires\Sentinels\Support\Validation\ValidatesPayment;
use Vampires\Sentinels\Tests\TestCase;

class ReleaseRegressionTest extends TestCase
{
    public function test_invalid_order_items_keep_field_paths_and_flat_error_messages(): void
    {
        $agent = new ReleaseValidationAgent();
        $result = $agent->checkItems([
            ['id' => 'first', 'quantity' => 0, 'price' => -1],
            ['quantity' => 1, 'price' => 2],
        ]);

        $this->assertFalse($result->valid);
        $this->assertSame(['Item quantity must be a positive number'], $result->getErrors('item_0.quantity'));
        $this->assertSame(['Item price must be a non-negative number'], $result->getErrors('item_0.price'));
        $this->assertSame(['Item id is required'], $result->getErrors('item_1.id'));
        $this->assertSame([
            'Item quantity must be a positive number',
            'Item price must be a non-negative number',
            'Item id is required',
        ], $result->getAllErrors());
        $this->assertStringContainsString('Item quantity must be a positive number', $result->getErrorString());

        $items = [['id' => 'first', 'quantity' => 2, 'price' => 3, 'line_total' => 6]];
        $valid = $agent->checkItems($items);
        $this->assertTrue($valid->valid);
        $this->assertSame($items, $valid->sanitizedInput);
    }

    public function test_payment_security_blocks_private_ranges_and_accepts_public_addresses(): void
    {
        $agent = new ReleaseValidationAgent();
        foreach (['10.255.255.255', '172.16.0.0', '172.31.255.255', '192.168.1.1', '127.0.0.1'] as $ip) {
            $context = Context::create([])->withMetadata('client_ip', $ip)
                ->withMetadata('user_agent', 'release-test')->withTag('authenticated');
            $result = $agent->checkSecurity($context);
            $this->assertFalse($result->valid, $ip);
            $this->assertContains('Payment from suspicious IP address', $result->getErrors('security'));
        }

        foreach (['8.8.8.8', '172.15.255.255', '172.32.0.0'] as $ip) {
            $context = Context::create([])->withMetadata('client_ip', $ip)
                ->withMetadata('user_agent', 'release-test')->withTag('authenticated');
            $this->assertTrue($agent->checkSecurity($context)->valid, $ip);
        }
    }

    public function test_cidr_matching_handles_prefix_edges_and_malformed_inputs(): void
    {
        $agent = new ReleaseValidationAgent();
        $this->assertTrue($agent->matchesIpRange('203.0.113.1', '0.0.0.0/0'));
        $this->assertTrue($agent->matchesIpRange('203.0.113.1', '203.0.113.1/32'));
        $this->assertFalse($agent->matchesIpRange('203.0.113.2', '203.0.113.1/32'));
        $this->assertFalse($agent->matchesIpRange('invalid', '0.0.0.0/0'));

        foreach (['invalid/24', '10.0.0.0', '10.0.0.0/-1', '10.0.0.0/33', '10.0.0.0/no', '10.0.0.0/8/1'] as $range) {
            $this->assertFalse($agent->matchesIpRange('10.0.0.1', $range), $range);
        }
    }
}

class ReleaseValidationAgent extends BaseAgent
{
    use ValidatesOrder {
        validateOrderItems as public checkItems;
    }
    use ValidatesPayment {
        validatePaymentSecurity as public checkSecurity;
        ipInRange as public matchesIpRange;
    }

    protected function handle(Context $context): Context
    {
        return $context;
    }
}
