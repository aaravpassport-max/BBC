#!/bin/bash
# ══════════════════════════════════════════════════
#  ILRS - Intelligent Life Reminder System
#  Auto Setup Script
# ══════════════════════════════════════════════════

echo ""
echo "██████████████████████████████████████████████"
echo "  🧠  ILRS — Intelligent Life Reminder System"
echo "        Installing... Please wait..."
echo "██████████████████████████████████████████████"
echo ""

# Check Node.js
if ! command -v node &> /dev/null; then
    echo "❌ Node.js not found!"
    echo "   Please install Node.js from: https://nodejs.org"
    echo "   (Download the LTS version)"
    read -p "Press Enter to exit..."
    exit 1
fi

NODE_VERSION=$(node --version)
echo "✅ Node.js found: $NODE_VERSION"

# Check npm
if ! command -v npm &> /dev/null; then
    echo "❌ npm not found!"
    exit 1
fi

echo "📦 Installing dependencies..."
npm install

if [ $? -ne 0 ]; then
    echo ""
    echo "❌ Installation failed!"
    echo "   Try running: npm install --legacy-peer-deps"
    read -p "Press Enter to exit..."
    exit 1
fi

echo ""
echo "✅ Installation complete!"
echo ""
echo "To start ILRS, run:"
echo "   npm start"
echo ""
read -p "Press Enter to start ILRS now..."
npm start
