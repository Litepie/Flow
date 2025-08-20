<?php

namespace App\Examples\Integrations\Deployment;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/**
 * Deployment and DevOps Integration Examples
 * 
 * This file demonstrates deployment and DevOps integration patterns:
 * - CI/CD Pipeline Integration
 * - Docker Container Deployment
 * - Kubernetes Orchestration
 * - Blue-Green Deployment
 * - Canary Deployment
 * - Infrastructure as Code
 * - Health Checks and Monitoring
 * - Rollback Strategies
 */

// 1. CI/CD Pipeline Integration
class CICDPipelineIntegration
{
    private array $pipelines;

    public function __construct()
    {
        $this->pipelines = [
            'github_actions' => new GitHubActionsIntegration(),
            'gitlab_ci' => new GitLabCIIntegration(),
            'jenkins' => new JenkinsIntegration(),
            'azure_devops' => new AzureDevOpsIntegration(),
        ];
    }

    public function triggerDeployment(string $environment, string $version, array $options = []): DeploymentResult
    {
        $pipeline = $this->pipelines[$options['pipeline'] ?? 'github_actions'];
        
        Log::info('Triggering deployment', [
            'environment' => $environment,
            'version' => $version,
            'pipeline' => get_class($pipeline),
        ]);

        try {
            // Pre-deployment checks
            $this->runPreDeploymentChecks($environment, $version);
            
            // Trigger deployment
            $result = $pipeline->deploy($environment, $version, $options);
            
            if ($result->isSuccessful()) {
                // Post-deployment tasks
                $this->runPostDeploymentTasks($environment, $version);
                
                Log::info('Deployment completed successfully', [
                    'environment' => $environment,
                    'version' => $version,
                    'deployment_id' => $result->getDeploymentId(),
                ]);
            } else {
                Log::error('Deployment failed', [
                    'environment' => $environment,
                    'version' => $version,
                    'error' => $result->getError(),
                ]);
            }
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error('Deployment exception', [
                'environment' => $environment,
                'version' => $version,
                'error' => $e->getMessage(),
            ]);
            
            throw new DeploymentException("Deployment failed: " . $e->getMessage());
        }
    }

    private function runPreDeploymentChecks(string $environment, string $version): void
    {
        // Database migration checks
        $this->checkPendingMigrations();
        
        // Configuration validation
        $this->validateConfiguration($environment);
        
        // Security checks
        $this->runSecurityChecks();
        
        // Dependency checks
        $this->checkDependencies();
        
        // Workflow compatibility checks
        $this->checkWorkflowCompatibility($version);
    }

    private function runPostDeploymentTasks(string $environment, string $version): void
    {
        // Run database migrations
        Artisan::call('migrate', ['--force' => true]);
        
        // Clear caches
        Artisan::call('cache:clear');
        Artisan::call('config:clear');
        Artisan::call('view:clear');
        
        // Update workflow definitions
        $this->updateWorkflowDefinitions($version);
        
        // Health checks
        $this->runHealthChecks($environment);
        
        // Warm up caches
        $this->warmUpCaches();
    }

    private function checkPendingMigrations(): void
    {
        $pendingMigrations = Artisan::call('migrate:status', ['--pending' => true]);
        if ($pendingMigrations) {
            Log::info('Pending migrations detected', ['count' => count($pendingMigrations)]);
        }
    }

    private function validateConfiguration(string $environment): void
    {
        $requiredVars = [
            'APP_ENV',
            'APP_KEY',
            'DB_CONNECTION',
            'FLOW_DEFAULT_WORKFLOW',
        ];

        foreach ($requiredVars as $var) {
            if (!env($var)) {
                throw new ConfigurationException("Required environment variable {$var} is not set");
            }
        }
    }

    private function runSecurityChecks(): void
    {
        // Check for security vulnerabilities
        Process::run('composer audit');
        
        // Validate SSL certificates
        $this->validateSSLCertificates();
        
        // Check file permissions
        $this->checkFilePermissions();
    }

    private function checkDependencies(): void
    {
        // Verify composer dependencies
        Process::run('composer validate');
        
        // Check NPM dependencies if applicable
        if (file_exists('package.json')) {
            Process::run('npm audit');
        }
    }

    private function checkWorkflowCompatibility(string $version): void
    {
        // Load existing workflows
        $existingWorkflows = $this->getExistingWorkflows();
        
        // Check for breaking changes
        foreach ($existingWorkflows as $workflow) {
            if (!$this->isWorkflowCompatible($workflow, $version)) {
                throw new WorkflowCompatibilityException(
                    "Workflow {$workflow} is not compatible with version {$version}"
                );
            }
        }
    }

    private function updateWorkflowDefinitions(string $version): void
    {
        // Update workflow schemas if needed
        Artisan::call('flow:update-schemas', ['--version' => $version]);
    }

    private function runHealthChecks(string $environment): void
    {
        $healthChecker = new HealthCheckRunner();
        $healthChecker->runAll($environment);
    }

    private function warmUpCaches(): void
    {
        // Warm up workflow caches
        Artisan::call('flow:cache-workflows');
        
        // Warm up route cache
        Artisan::call('route:cache');
        
        // Warm up application cache
        Artisan::call('config:cache');
    }

    private function getExistingWorkflows(): array
    {
        // Return list of existing workflows
        return ['order_processing', 'user_registration', 'document_approval'];
    }

    private function isWorkflowCompatible(string $workflow, string $version): bool
    {
        // Check workflow compatibility logic
        return true; // Simplified for example
    }

    private function validateSSLCertificates(): void
    {
        // Validate SSL certificates for secure connections
        $certificates = config('deployment.ssl_certificates', []);
        
        foreach ($certificates as $domain => $certPath) {
            if (!$this->isSSLCertificateValid($certPath)) {
                throw new SecurityException("SSL certificate for {$domain} is invalid or expired");
            }
        }
    }

    private function checkFilePermissions(): void
    {
        $criticalFiles = [
            storage_path() => '775',
            storage_path('logs') => '775',
            bootstrap_path('cache') => '775',
        ];

        foreach ($criticalFiles as $path => $expectedPermission) {
            $actualPermission = substr(sprintf('%o', fileperms($path)), -3);
            if ($actualPermission !== $expectedPermission) {
                Log::warning("File permission mismatch", [
                    'path' => $path,
                    'expected' => $expectedPermission,
                    'actual' => $actualPermission,
                ]);
            }
        }
    }

    private function isSSLCertificateValid(string $certPath): bool
    {
        if (!file_exists($certPath)) {
            return false;
        }

        $cert = openssl_x509_parse(file_get_contents($certPath));
        return $cert && $cert['validTo_time_t'] > time();
    }
}

// 2. Docker Container Integration
class DockerContainerIntegration
{
    private array $registries;

    public function __construct()
    {
        $this->registries = [
            'docker_hub' => new DockerHubRegistry(),
            'ecr' => new ECRRegistry(),
            'gcr' => new GCRRegistry(),
            'acr' => new ACRRegistry(),
        ];
    }

    public function buildAndPushImage(string $version, array $options = []): ContainerResult
    {
        $registry = $this->registries[$options['registry'] ?? 'docker_hub'];
        $imageName = $options['image_name'] ?? 'litepie-flow-app';
        $tag = $options['tag'] ?? $version;

        try {
            // Build Docker image
            $buildResult = $this->buildDockerImage($imageName, $tag, $options);
            
            if (!$buildResult->isSuccessful()) {
                return ContainerResult::failed('Docker build failed: ' . $buildResult->getError());
            }

            // Push to registry
            $pushResult = $registry->push($imageName, $tag);
            
            if (!$pushResult->isSuccessful()) {
                return ContainerResult::failed('Registry push failed: ' . $pushResult->getError());
            }

            // Generate deployment manifests
            $this->generateKubernetesManifests($imageName, $tag);

            return ContainerResult::success([
                'image' => "{$imageName}:{$tag}",
                'registry' => get_class($registry),
                'size' => $buildResult->getImageSize(),
                'digest' => $pushResult->getDigest(),
            ]);

        } catch (\Exception $e) {
            Log::error('Container build/push failed', [
                'image' => $imageName,
                'tag' => $tag,
                'error' => $e->getMessage(),
            ]);

            return ContainerResult::failed($e->getMessage());
        }
    }

    private function buildDockerImage(string $imageName, string $tag, array $options): BuildResult
    {
        $dockerfile = $options['dockerfile'] ?? 'Dockerfile';
        $context = $options['context'] ?? '.';
        
        $command = [
            'docker', 'build',
            '-f', $dockerfile,
            '-t', "{$imageName}:{$tag}",
            '--no-cache',
            $context
        ];

        // Add build arguments
        if (isset($options['build_args'])) {
            foreach ($options['build_args'] as $key => $value) {
                $command[] = '--build-arg';
                $command[] = "{$key}={$value}";
            }
        }

        $result = Process::run(implode(' ', $command));

        if ($result->successful()) {
            // Get image size
            $sizeResult = Process::run("docker image inspect {$imageName}:{$tag} --format='{{.Size}}'");
            $imageSize = $sizeResult->successful() ? (int)$sizeResult->output() : 0;

            return BuildResult::success(['size' => $imageSize]);
        } else {
            return BuildResult::failed($result->errorOutput());
        }
    }

    private function generateKubernetesManifests(string $imageName, string $tag): void
    {
        $manifests = [
            'deployment' => $this->generateDeploymentManifest($imageName, $tag),
            'service' => $this->generateServiceManifest(),
            'configmap' => $this->generateConfigMapManifest(),
            'secret' => $this->generateSecretManifest(),
        ];

        foreach ($manifests as $type => $manifest) {
            file_put_contents(
                "k8s/{$type}.yaml",
                $manifest
            );
        }
    }

    private function generateDeploymentManifest(string $imageName, string $tag): string
    {
        return "
apiVersion: apps/v1
kind: Deployment
metadata:
  name: litepie-flow-app
  labels:
    app: litepie-flow
    version: {$tag}
spec:
  replicas: 3
  selector:
    matchLabels:
      app: litepie-flow
  template:
    metadata:
      labels:
        app: litepie-flow
        version: {$tag}
    spec:
      containers:
      - name: app
        image: {$imageName}:{$tag}
        ports:
        - containerPort: 8080
        env:
        - name: APP_ENV
          value: production
        - name: DB_HOST
          valueFrom:
            secretKeyRef:
              name: app-secrets
              key: db-host
        livenessProbe:
          httpGet:
            path: /health
            port: 8080
          initialDelaySeconds: 30
          periodSeconds: 10
        readinessProbe:
          httpGet:
            path: /ready
            port: 8080
          initialDelaySeconds: 5
          periodSeconds: 5
        resources:
          requests:
            memory: \"256Mi\"
            cpu: \"250m\"
          limits:
            memory: \"512Mi\"
            cpu: \"500m\"
";
    }

    private function generateServiceManifest(): string
    {
        return "
apiVersion: v1
kind: Service
metadata:
  name: litepie-flow-service
spec:
  selector:
    app: litepie-flow
  ports:
  - protocol: TCP
    port: 80
    targetPort: 8080
  type: LoadBalancer
";
    }

    private function generateConfigMapManifest(): string
    {
        return "
apiVersion: v1
kind: ConfigMap
metadata:
  name: app-config
data:
  app.env: production
  flow.default_workflow: order_processing
  cache.driver: redis
";
    }

    private function generateSecretManifest(): string
    {
        return "
apiVersion: v1
kind: Secret
metadata:
  name: app-secrets
type: Opaque
data:
  db-host: " . base64_encode('mysql.internal') . "
  db-password: " . base64_encode('secret') . "
  app-key: " . base64_encode(config('app.key')) . "
";
    }
}

// 3. Blue-Green Deployment
class BlueGreenDeploymentIntegration
{
    private string $currentEnvironment;

    public function __construct()
    {
        $this->currentEnvironment = $this->getCurrentEnvironment();
    }

    public function performBlueGreenDeployment(string $version, array $options = []): BlueGreenResult
    {
        $targetEnvironment = $this->getTargetEnvironment();
        $loadBalancer = new LoadBalancerController();

        try {
            Log::info('Starting blue-green deployment', [
                'version' => $version,
                'current_env' => $this->currentEnvironment,
                'target_env' => $targetEnvironment,
            ]);

            // Step 1: Deploy to target environment
            $deployResult = $this->deployToEnvironment($targetEnvironment, $version);
            
            if (!$deployResult->isSuccessful()) {
                return BlueGreenResult::failed('Deployment to target environment failed');
            }

            // Step 2: Run health checks on target environment
            $healthResult = $this->runHealthChecks($targetEnvironment);
            
            if (!$healthResult->isHealthy()) {
                $this->rollbackEnvironment($targetEnvironment);
                return BlueGreenResult::failed('Health checks failed on target environment');
            }

            // Step 3: Run smoke tests
            $smokeTestResult = $this->runSmokeTests($targetEnvironment);
            
            if (!$smokeTestResult->isPassed()) {
                $this->rollbackEnvironment($targetEnvironment);
                return BlueGreenResult::failed('Smoke tests failed');
            }

            // Step 4: Switch traffic (gradual or immediate)
            if ($options['gradual_switch'] ?? false) {
                $switchResult = $this->performGradualTrafficSwitch($targetEnvironment);
            } else {
                $switchResult = $loadBalancer->switchTraffic($this->currentEnvironment, $targetEnvironment);
            }

            if (!$switchResult->isSuccessful()) {
                $this->rollbackEnvironment($targetEnvironment);
                return BlueGreenResult::failed('Traffic switch failed');
            }

            // Step 5: Monitor new environment
            $this->monitorEnvironment($targetEnvironment);

            // Step 6: Clean up old environment
            $this->scheduleOldEnvironmentCleanup($this->currentEnvironment);

            Log::info('Blue-green deployment completed successfully', [
                'version' => $version,
                'new_environment' => $targetEnvironment,
            ]);

            return BlueGreenResult::success([
                'version' => $version,
                'environment' => $targetEnvironment,
                'previous_environment' => $this->currentEnvironment,
                'deployment_time' => now(),
            ]);

        } catch (\Exception $e) {
            Log::error('Blue-green deployment failed', [
                'version' => $version,
                'error' => $e->getMessage(),
            ]);

            // Ensure we rollback on any failure
            $this->rollbackEnvironment($targetEnvironment);
            
            return BlueGreenResult::failed($e->getMessage());
        }
    }

    private function getCurrentEnvironment(): string
    {
        // Determine current active environment (blue or green)
        return cache()->get('active_environment', 'blue');
    }

    private function getTargetEnvironment(): string
    {
        return $this->currentEnvironment === 'blue' ? 'green' : 'blue';
    }

    private function deployToEnvironment(string $environment, string $version): DeploymentResult
    {
        $deployer = new EnvironmentDeployer();
        return $deployer->deploy($environment, $version);
    }

    private function runHealthChecks(string $environment): HealthResult
    {
        $healthChecker = new HealthCheckRunner();
        return $healthChecker->checkEnvironment($environment);
    }

    private function runSmokeTests(string $environment): TestResult
    {
        $smokeTestRunner = new SmokeTestRunner();
        return $smokeTestRunner->runTests($environment);
    }

    private function performGradualTrafficSwitch(string $targetEnvironment): SwitchResult
    {
        $loadBalancer = new LoadBalancerController();
        $steps = [10, 25, 50, 75, 100]; // Percentage steps

        foreach ($steps as $percentage) {
            Log::info("Switching {$percentage}% traffic to {$targetEnvironment}");
            
            $result = $loadBalancer->adjustTrafficPercentage($targetEnvironment, $percentage);
            
            if (!$result->isSuccessful()) {
                return SwitchResult::failed("Failed to switch {$percentage}% traffic");
            }

            // Wait and monitor before next step
            sleep(30);
            
            $healthResult = $this->runHealthChecks($targetEnvironment);
            
            if (!$healthResult->isHealthy()) {
                // Rollback traffic
                $loadBalancer->adjustTrafficPercentage($this->currentEnvironment, 100);
                return SwitchResult::failed("Health check failed at {$percentage}% traffic");
            }
        }

        return SwitchResult::success();
    }

    private function rollbackEnvironment(string $environment): void
    {
        Log::warning("Rolling back environment: {$environment}");
        
        $deployer = new EnvironmentDeployer();
        $deployer->rollback($environment);
    }

    private function monitorEnvironment(string $environment): void
    {
        // Set up enhanced monitoring for the new environment
        $monitor = new EnvironmentMonitor();
        $monitor->enableEnhancedMonitoring($environment, 24); // 24 hours
    }

    private function scheduleOldEnvironmentCleanup(string $environment): void
    {
        // Schedule cleanup of old environment after confirmation period
        EnvironmentCleanupJob::dispatch($environment)->delay(now()->addHours(24));
    }
}

// 4. Canary Deployment
class CanaryDeploymentIntegration
{
    public function performCanaryDeployment(string $version, array $options = []): CanaryResult
    {
        $canaryPercentage = $options['canary_percentage'] ?? 5;
        $monitoringDuration = $options['monitoring_duration'] ?? 300; // 5 minutes
        
        try {
            Log::info('Starting canary deployment', [
                'version' => $version,
                'canary_percentage' => $canaryPercentage,
            ]);

            // Deploy canary version
            $canaryResult = $this->deployCanaryVersion($version, $canaryPercentage);
            
            if (!$canaryResult->isSuccessful()) {
                return CanaryResult::failed('Canary deployment failed');
            }

            // Monitor canary for specified duration
            $monitoringResult = $this->monitorCanaryDeployment($version, $monitoringDuration);
            
            if (!$monitoringResult->isSuccessful()) {
                $this->rollbackCanary($version);
                return CanaryResult::failed('Canary monitoring detected issues');
            }

            // Gradually increase canary traffic
            $rolloutResult = $this->performGradualRollout($version);
            
            if (!$rolloutResult->isSuccessful()) {
                $this->rollbackCanary($version);
                return CanaryResult::failed('Gradual rollout failed');
            }

            Log::info('Canary deployment completed successfully', ['version' => $version]);

            return CanaryResult::success([
                'version' => $version,
                'final_percentage' => 100,
                'deployment_time' => now(),
            ]);

        } catch (\Exception $e) {
            Log::error('Canary deployment failed', [
                'version' => $version,
                'error' => $e->getMessage(),
            ]);

            $this->rollbackCanary($version);
            return CanaryResult::failed($e->getMessage());
        }
    }

    private function deployCanaryVersion(string $version, int $percentage): DeploymentResult
    {
        $canaryDeployer = new CanaryDeployer();
        return $canaryDeployer->deploy($version, $percentage);
    }

    private function monitorCanaryDeployment(string $version, int $duration): MonitoringResult
    {
        $monitor = new CanaryMonitor();
        
        $startTime = time();
        $endTime = $startTime + $duration;
        
        while (time() < $endTime) {
            $metrics = $monitor->getCanaryMetrics($version);
            
            // Check for anomalies
            if ($this->detectAnomalies($metrics)) {
                return MonitoringResult::failed('Anomalies detected in canary metrics');
            }
            
            sleep(10); // Check every 10 seconds
        }
        
        return MonitoringResult::success();
    }

    private function performGradualRollout(string $version): RolloutResult
    {
        $steps = [10, 25, 50, 75, 100];
        $loadBalancer = new LoadBalancerController();
        
        foreach ($steps as $percentage) {
            Log::info("Rolling out to {$percentage}% of traffic", ['version' => $version]);
            
            $result = $loadBalancer->adjustCanaryPercentage($version, $percentage);
            
            if (!$result->isSuccessful()) {
                return RolloutResult::failed("Failed to rollout to {$percentage}%");
            }
            
            // Monitor for a short period at each step
            sleep(60);
            
            $monitor = new CanaryMonitor();
            $metrics = $monitor->getCanaryMetrics($version);
            
            if ($this->detectAnomalies($metrics)) {
                return RolloutResult::failed("Anomalies detected at {$percentage}% rollout");
            }
        }
        
        return RolloutResult::success();
    }

    private function rollbackCanary(string $version): void
    {
        Log::warning("Rolling back canary deployment", ['version' => $version]);
        
        $canaryDeployer = new CanaryDeployer();
        $canaryDeployer->rollback($version);
    }

    private function detectAnomalies(array $metrics): bool
    {
        // Implement anomaly detection logic
        $errorRateThreshold = 0.05; // 5%
        $latencyThreshold = 2000; // 2 seconds
        
        if ($metrics['error_rate'] > $errorRateThreshold) {
            return true;
        }
        
        if ($metrics['avg_latency'] > $latencyThreshold) {
            return true;
        }
        
        return false;
    }
}

// Usage Examples
class DeploymentIntegrationUsageExamples
{
    public function demonstrateCICDDeployment(): void
    {
        $cicd = new CICDPipelineIntegration();
        
        $result = $cicd->triggerDeployment('production', 'v1.2.3', [
            'pipeline' => 'github_actions',
            'auto_migrate' => true,
            'run_tests' => true,
        ]);
        
        if ($result->isSuccessful()) {
            echo "Deployment completed successfully\n";
            echo "Deployment ID: " . $result->getDeploymentId() . "\n";
        } else {
            echo "Deployment failed: " . $result->getError() . "\n";
        }
    }

    public function demonstrateBlueGreenDeployment(): void
    {
        $blueGreen = new BlueGreenDeploymentIntegration();
        
        $result = $blueGreen->performBlueGreenDeployment('v1.3.0', [
            'gradual_switch' => true,
            'health_check_timeout' => 300,
        ]);
        
        if ($result->isSuccessful()) {
            echo "Blue-green deployment completed\n";
            echo "New environment: " . $result->getEnvironment() . "\n";
        } else {
            echo "Blue-green deployment failed: " . $result->getError() . "\n";
        }
    }

    public function demonstrateCanaryDeployment(): void
    {
        $canary = new CanaryDeploymentIntegration();
        
        $result = $canary->performCanaryDeployment('v1.3.1', [
            'canary_percentage' => 10,
            'monitoring_duration' => 600, // 10 minutes
        ]);
        
        if ($result->isSuccessful()) {
            echo "Canary deployment completed\n";
            echo "Version: " . $result->getVersion() . "\n";
        } else {
            echo "Canary deployment failed: " . $result->getError() . "\n";
        }
    }

    public function demonstrateDockerIntegration(): void
    {
        $docker = new DockerContainerIntegration();
        
        $result = $docker->buildAndPushImage('v1.3.2', [
            'registry' => 'ecr',
            'image_name' => 'my-app/litepie-flow',
            'build_args' => [
                'APP_ENV' => 'production',
                'COMPOSER_AUTH' => '{}',
            ],
        ]);
        
        if ($result->isSuccessful()) {
            echo "Container build and push completed\n";
            echo "Image: " . $result->getImage() . "\n";
            echo "Digest: " . $result->getDigest() . "\n";
        } else {
            echo "Container operation failed: " . $result->getError() . "\n";
        }
    }
}
