<?php

namespace Tests\Feature\Deployment;

use Tests\TestCase;

class UptimeMonitoringArtifactTest extends TestCase
{
    private string $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->template = dirname(__DIR__, 4).'/infrastructure/cloudformation/atlas-uptime-monitor.yml';
    }

    public function test_monitor_checks_production_readiness_from_multiple_regions(): void
    {
        $contents = file_get_contents($this->template);

        $this->assertIsString($contents);
        $this->assertStringContainsString('Default: theclearmove.com', $contents);
        $this->assertStringContainsString('Default: /api/ready', $contents);
        $this->assertStringContainsString('Type: HTTPS_STR_MATCH', $contents);
        $this->assertStringContainsString('RequestInterval: 30', $contents);
        $this->assertStringContainsString('FailureThreshold: 3', $contents);
        $this->assertStringContainsString('- us-east-1', $contents);
        $this->assertStringContainsString('- us-west-1', $contents);
        $this->assertStringContainsString('- eu-west-1', $contents);
    }

    public function test_monitor_alerts_a_named_operator_on_failure_and_recovery(): void
    {
        $contents = file_get_contents($this->template);

        $this->assertIsString($contents);
        $this->assertStringContainsString('Endpoint: !Ref AlertEmail', $contents);
        $this->assertStringContainsString('Protocol: email', $contents);
        $this->assertStringContainsString('MetricName: HealthCheckStatus', $contents);
        $this->assertStringContainsString('EvaluationPeriods: 2', $contents);
        $this->assertStringContainsString('DatapointsToAlarm: 2', $contents);
        $this->assertStringContainsString('TreatMissingData: breaching', $contents);
        $this->assertStringContainsString("AlarmActions:\n        - !Ref AtlasUptimeAlerts", $contents);
        $this->assertStringContainsString("OKActions:\n        - !Ref AtlasUptimeAlerts", $contents);
    }

    public function test_expected_response_is_parameterized_for_a_safe_alert_drill(): void
    {
        $contents = file_get_contents($this->template);

        $this->assertIsString($contents);
        $this->assertStringContainsString('ExpectedContent:', $contents);
        $this->assertStringContainsString("Default: '\"status\":\"ok\"'", $contents);
        $this->assertStringContainsString('SearchString: !Ref ExpectedContent', $contents);
    }
}
