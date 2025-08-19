# Workflows

This document explains how to define and use workflows in the Litepie Flow package.

## Defining a Workflow

Workflows are defined as classes that return a `Workflow` instance. You can define states, transitions, and actions.

## Registering Workflows

Register your workflow in a service provider or in the config file.

## Using Workflows

Attach the `HasWorkflow` trait to your Eloquent models and implement the `Workflowable` contract.

See the main README for code examples.
