<?php

namespace Guzzle\Service\Command;

use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Http\Message\Response;
use Guzzle\Service\Command\LocationVisitor\VisitorFlyweight;
use Guzzle\Service\Command\LocationVisitor\Response\ResponseVisitorInterface;
use Guzzle\Service\Description\Parameter;
use Guzzle\Service\Description\OperationInterface;
use Guzzle\Service\Description\Operation;
use Guzzle\Service\Resource\Model;

/**
 * Response parser that attempts to marshal responses into an associative array based on models in a service description
 */
class OperationResponseParser extends DefaultResponseParser
{
    /**
     * @var VisitorFlyweight $factory Visitor factory
     */
    protected $factory;

    /**
     * @var self
     */
    protected static $instance;

    /**
     * Get a cached default instance of the Operation response parser that uses default visitors
     *
     * @return self
     * @codeCoverageIgnore
     */
    public static function getInstance()
    {
        if (!static::$instance) {
            static::$instance = new static(VisitorFlyweight::getInstance());
        }

        return static::$instance;
    }

    /**
     * @param VisitorFlyweight $factory Factory to use when creating visitors
     */
    public function __construct(VisitorFlyweight $factory)
    {
        $this->factory = $factory;
    }

    /**
     * Add a location visitor to the command
     *
     * @param string                   $location Location to associate with the visitor
     * @param ResponseVisitorInterface $visitor  Visitor to attach
     *
     * @return self
     */
    public function addVisitor($location, ResponseVisitorInterface $visitor)
    {
        $this->factory->addResponseVisitor($location, $visitor);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function handleParsing(AbstractCommand $command, Response $response, $contentType)
    {
        $operation = $command->getOperation();
        $model = $operation->getResponseType() == OperationInterface::TYPE_MODEL
            ? $operation->getServiceDescription()->getModel($operation->getResponseClass())
            : null;

        if (!$model) {
            // Return basic processing if the responseType is not model or the model cannot be found
            return parent::handleParsing($command, $response, $contentType);
        } elseif ($command->get(AbstractCommand::RESPONSE_PROCESSING) != AbstractCommand::TYPE_MODEL) {
            // Returns a model with no visiting if the command response processing is not model
            return new Model(parent::handleParsing($command, $response, $contentType), $model);
        } else {
            return new Model($this->visitResult($model, $command, $response), $model);
        }
    }

    /**
     * Perform transformations on the result array
     *
     * @param Parameter        $model    Model that defines the structure
     * @param CommandInterface $command  Command that performed the operation
     * @param Response         $response Response received
     *
     * @return array Returns the array of result data
     * @throws InvalidArgumentException when an invalid model is encountered
     */
    protected function visitResult(
        Parameter $model,
        CommandInterface $command,
        Response $response
    ) {
        switch ($model->getType()) {
            case 'object':
                return $this->parseObject($model, $command, $response);
            case 'array':
                return $this->parseArray($model, $command, $response);
            default:
                throw new InvalidArgumentException($model->getType() . ' is not a supported response model type');
        }
    }

    protected function parseObject(
        Parameter $model,
        CommandInterface $command,
        Response $response
    ) {
        $foundVisitors = $result = array();
        $props = $model->getProperties();

        foreach ($props as $schema) {
            if ($location = $schema->getLocation()) {
                // Trigger the before method on the first found visitor of this type
                if (!isset($foundVisitors[$location])) {
                    $foundVisitors[$location] = $this->factory->getResponseVisitor($location);
                    $foundVisitors[$location]->before($command, $result);
                }
            }
        }

        // Apply the parameter value with the location visitor
        foreach ($props as $schema) {
            if ($location = $schema->getLocation()) {
                $foundVisitors[$location]->visit($command, $response, $schema, $result);
            }
        }

        // Call the after() method of each found visitor
        foreach ($foundVisitors as $visitor) {
            $visitor->after($command);
        }

        return $result;
    }

    protected function parseArray(
        Parameter $model,
        CommandInterface $command,
        Response $response
    ) {
        $result = array();
        $visitor = $this->factory->getResponseVisitor($model->getLocation());
        $visitor->before($command, $result);
        $result = array('items' => $result);
        $current = $model->getName();
        $model->setName('items');
        $visitor->visit($command, $response, $model, $result);
        $visitor->after($command);
        $model->setName($current);

        return $result['items'];
    }
}
