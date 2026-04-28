#!/bin/bash

# E-Learning Platform - Database Setup Script

echo "========================================="
echo "E-Learning Platform - Database Setup"
echo "========================================="
echo ""

# Create PostgreSQL database
echo "Creating PostgreSQL database..."
sudo -u postgres psql -c "CREATE DATABASE elearning;" 2>/dev/null || echo "Database 'elearning' already exists or PostgreSQL service is not running."

echo ""
echo "Database setup complete!"
echo ""
echo "Next steps:"
echo "1. Configure your .env file with PostgreSQL credentials (already done)"
echo "2. Run: php artisan migrate"
echo ""
