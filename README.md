# Sentinels: Powerful, Flexible, and Simple Agent-Based Task Execution for Laravel

Sentinels is a cutting-edge, agent-based task execution framework for Laravel that combines power and flexibility with simplicity and ease of use. It empowers developers to create, manage, and execute single-purpose agents in a highly efficient pipeline architecture.

## 🚀 Key Features

- **Invokable Agents**: Create powerful, single-purpose agents with a clean, invokable interface.
- **Flexible Pipeline Architecture**: Chain multiple agents together for complex task execution.
- **Dynamic Agent Routing**: Route tasks to specific agents based on custom conditions.
- **Easy Integration**: Seamlessly integrates with Laravel projects.
- **Extensible Design**: Easily extend and customize to fit your specific needs.
- **Performance Optimized**: Designed for efficient execution of complex task chains.

## 🛠 Installation

Install Sentinels via Composer:

```bash
composer require vampires/sentinels
```

## 🎯 Quick Start

Here's a simple example to get you started with Sentinels:

```php
use Vampires\Sentinels\Agents\AgentManager;
use Vampires\Sentinels\Agents\BaseAgent;

// Define a simple agent
class UppercaseAgent extends BaseAgent
{
    public function __invoke($input = null)
    {
        return strtoupper($input);
    }
}

// Use the agent in a pipeline
$manager = new AgentManager();
$result = $manager
    ->addAgent(new UppercaseAgent())
    ->pipeline("hello world");

echo $result; // Outputs: HELLO WORLD
```

## 🧠 Core Concepts

### Creating an Agent

Agents are the building blocks of Sentinels. Create a new agent by extending the `BaseAgent` class:

```php
use Vampires\Sentinels\Agents\BaseAgent;

class MyCustomAgent extends BaseAgent
{
    public function __invoke($input = null)
    {
        // Your agent logic here
        return $input;
    }
}
```

### Using the AgentManager

The `AgentManager` allows you to create powerful pipelines by chaining multiple agents:

```php
use Vampires\Sentinels\Agents\AgentManager;

$manager = new AgentManager();
$result = $manager
    ->addAgent(new DataFetchAgent())
    ->addAgent(new DataTransformAgent())
    ->addAgent(new DataValidationAgent())
    ->executeAll($initialData);
```

### Dynamic Agent Routing

Use the `AgentRouter` to dynamically route tasks to specific agents:

```php
use Vampires\Sentinels\Agents\AgentRouter;

$router = new AgentRouter();
$router
    ->addRoute("email", new EmailAgent())
    ->addRoute("sms", new SmsAgent());

$agent = $router->route("email task");
$result = $agent("Send this email");
```

## 🧪 Testing

Sentinels comes with a comprehensive test suite. Run the tests with:

```bash
composer test
```

## 🤝 Contributing

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details on how to contribute to Sentinels.

## 🔒 Security

If you discover any security-related issues, please email security@example.com instead of using the issue tracker.

## 👏 Credits

- [Your Name](https://github.com/yourusername)
- [All Contributors](../../contributors)

## 📄 License

Sentinels is open-sourced software licensed under the [MIT license](LICENSE.md).
