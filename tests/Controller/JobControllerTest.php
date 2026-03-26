<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Job;
use App\Enum\InputFormat;
use App\Enum\JobStatus;
use App\Enum\OutputFormat;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Functional end-to-end tests for JobController.
 * DummyConverter is replaced by FastConverter (see services_test.yaml) so no
 * sleep(130) blocks the suite.
 * Each test method truncates the jobs table. If the table does
 * not yet exist (first run without prior migration), SchemaTool creates it.
 */
final class JobControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->client        = static::createClient();
        $container           = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->uploadDir     = $container->getParameter('app.upload_dir');

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }

        $this->wipeUploadsDir();
        $this->resetDatabase();
    }

    // -------------------------------------------------------------------------
    // POST /api/jobs — validation
    // -------------------------------------------------------------------------

    public function testCreateJobReturnsProblemWhenFileIsMissing(): void
    {
        $this->client->request('POST', '/api/jobs', ['output_format' => 'json']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(
            'application/problem+json',
            $this->client->getResponse()->headers->get('Content-Type')
        );
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('detail', $data);
        $this->assertArrayHasKey('title', $data);
    }

    public function testCreateJobReturnsProblemWhenOutputFormatIsMissing(): void
    {
        $file = $this->makeJsonUpload();
        $this->client->request('POST', '/api/jobs', [], ['file' => $file]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(
            'application/problem+json',
            $this->client->getResponse()->headers->get('Content-Type')
        );
    }

    public function testCreateJobReturnsProblemWhenOutputFormatIsInvalid(): void
    {
        $file = $this->makeJsonUpload();
        $this->client->request('POST', '/api/jobs', ['output_format' => 'pdf'], ['file' => $file]);

        $this->assertResponseStatusCodeSame(422);
    }

    // -------------------------------------------------------------------------
    // POST /api/jobs → GET /api/jobs/{id} → GET /api/jobs/{id}/result  (E2E)
    // -------------------------------------------------------------------------

    /**
     * Full conversion workflow:
     *   1. POST creates the job and, thanks to sync:// messenger + FastConverter,
     *      the handler runs inline — job is completed before the response is sent.
     *   2. GET status confirms completed state and response shape.
     *   3. GET result returns the binary output file with the correct headers.
     */
    public function testFullConversionWorkflow(): void
    {
        // --- 1. Create job -------------------------------------------------------
        $file = $this->makeJsonUpload();
        $this->client->request('POST', '/api/jobs', ['output_format' => 'json'], ['file' => $file]);

        $this->assertResponseStatusCodeSame(202);

        $createData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('job_id', $createData);
        $this->assertArrayHasKey('status', $createData);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $createData['job_id']
        );

        $jobId = $createData['job_id'];

        // --- 2. Poll status — handler already ran synchronously ------------------
        $this->client->request('GET', '/api/jobs/' . $jobId);

        $this->assertResponseIsSuccessful();

        $statusData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame($jobId, $statusData['job_id']);
        $this->assertSame(JobStatus::Completed->value, $statusData['status']);
        $this->assertArrayHasKey('created_at', $statusData);
        $this->assertArrayHasKey('updated_at', $statusData);

        // --- 3. Download result --------------------------------------------------
        $this->client->request('GET', '/api/jobs/' . $jobId . '/result');

        $this->assertResponseIsSuccessful();
        $disposition = $this->client->getResponse()->headers->get('Content-Disposition', '');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString("job-{$jobId}.json", $disposition);
    }

    // -------------------------------------------------------------------------
    // GET /api/jobs/{id}
    // -------------------------------------------------------------------------

    public function testGetStatusReturnsBadRequestForInvalidUuid(): void
    {
        $this->client->request('GET', '/api/jobs/not-a-uuid');

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame(
            'application/problem+json',
            $this->client->getResponse()->headers->get('Content-Type')
        );
    }

    public function testGetStatusReturnsNotFoundForUnknownJob(): void
    {
        $this->client->request('GET', '/api/jobs/00000000-0000-0000-0000-000000000000');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testGetStatusReturnsJobData(): void
    {
        $job = $this->persistJob(new Job('/tmp/input.json', InputFormat::Json, OutputFormat::Json));

        $this->client->request('GET', '/api/jobs/' . $job->getId()->toRfc4122());

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame($job->getId()->toRfc4122(), $data['job_id']);
        $this->assertSame(JobStatus::Pending->value, $data['status']);
        $this->assertArrayHasKey('created_at', $data);
        $this->assertArrayHasKey('updated_at', $data);
        $this->assertNull($data['updated_at']);
    }

    // -------------------------------------------------------------------------
    // GET /api/jobs/{id}/result
    // -------------------------------------------------------------------------

    public function testGetResultReturnsConflictWhenJobIsPending(): void
    {
        $job = $this->persistJob(new Job('/tmp/input.json', InputFormat::Json, OutputFormat::Json));

        $this->client->request('GET', '/api/jobs/' . $job->getId()->toRfc4122() . '/result');

        $this->assertResponseStatusCodeSame(409);
        $this->assertSame(
            'application/problem+json',
            $this->client->getResponse()->headers->get('Content-Type')
        );
    }

    public function testGetResultReturnsBinaryFileWhenJobIsCompleted(): void
    {
        $outputPath = $this->uploadDir . '/test-completed-output.json';
        file_put_contents($outputPath, '{"converted":true}');

        $job = new Job('/tmp/input.json', InputFormat::Json, OutputFormat::Json);
        $job->markAsCompleted($outputPath);
        $this->persistJob($job);

        $this->client->request('GET', '/api/jobs/' . $job->getId()->toRfc4122() . '/result');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString(
            'attachment',
            $this->client->getResponse()->headers->get('Content-Disposition', '')
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeJsonUpload(): UploadedFile
    {
        $path = sys_get_temp_dir() . '/acube_test_' . uniqid('', true) . '.json';
        file_put_contents($path, '{"name":"Alice","age":30}');

        return new UploadedFile($path, 'data.json', 'application/json', null, true);
    }

    private function persistJob(Job $job): Job
    {
        $this->entityManager->persist($job);
        $this->entityManager->flush();
        $this->entityManager->clear();

        return $job;
    }

    private function resetDatabase(): void
    {
        try {
            $this->entityManager->getConnection()->executeStatement('DELETE FROM jobs');
            $this->entityManager->clear();
        } catch (\Exception) {
            $schemaTool = new SchemaTool($this->entityManager);
            $schemaTool->createSchema(
                $this->entityManager->getMetadataFactory()->getAllMetadata()
            );
        }
    }

    private function wipeUploadsDir(): void
    {
        foreach (glob($this->uploadDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}
