```
func RunTraining(
	symbol string,
	repo storage.CandleRepository,
	episodes int,
	modelSavePath string,
	candlesLimit int,
	isContinuous bool,
	log *zap.Logger,
) error {
	log.Info("Запуск обучения модели ИИ", zap.String("symbol", symbol), zap.Int("episodes", episodes))

	var (
		trainCandles []domain.DbCandle
		err          error
	)
	if isContinuous {
		// Для дообучения используем GetLastCandles (DESC), так как нам важен самый актуальный контекст рынка!
		trainCandles, err = repo.GetLastCandles(symbol, candlesLimit)
		if err != nil {
			return fmt.Errorf("ошибка загрузки свечей из БД для дообучения: %w", err)
		}

		if len(trainCandles) < candlesLimit {
			return fmt.Errorf("недостаточно данных в базе для дообучения")
		}
	} else {
		// Забираем ровно candlesLimit первых исторических свечей для обучения
		trainCandles, err = repo.GetCandlesForTraining(symbol, candlesLimit)
		if err != nil {
			return fmt.Errorf("ошибка выборки данных для обучения: %w", err)
		}
	}

	log.Info("Выборка данных успешно сформирована",
		zap.Int("train_candles_count", len(trainCandles)),
		zap.String("start_time", timeFromUnix(trainCandles[0].OpenTime)),
		zap.String("end_time", timeFromUnix(trainCandles[len(trainCandles)-1].OpenTime)),
	)

	// Инициализируем среду-симулятор
	env := sandbox.NewMarketEnv(trainCandles)

	policyNet := domain.NewNetwork(StateDim, HiddenDim, ActionDim, LR, log)
	targetNet := domain.NewNetwork(StateDim, HiddenDim, ActionDim, LR, log)
	if isContinuous {
		if err = policyNet.LoadFromFile(modelSavePath); err != nil {
			return fmt.Errorf("не удалось загрузить текущую модель для апдейта: %w", err)
		}
	}
	targetNet.CopyFrom(policyNet)

	treeBuf := NewSumTree(MemoryCapacity)
	epsilon := 1.0
	epsilonDecay := 0.985
	minEpsilon := 0.05
	totalSteps := 0
	if isContinuous {
		epsilon = minEpsilon
	}

	// Параметры для Prioritized Experience Replay
	alpha := 0.4 // Снизили с 0.6 для более плавного сэмплирования в Adam

	telemetry.DQNInsideEpsilon.WithLabelValues(symbol).Set(epsilon)

	for episode := 1; episode <= episodes; episode++ {
		env.Reset()
		stateSlice := env.GetStateValues()
		state := domain.NewMatrix(1, StateDim)
		for i, val := range stateSlice {
			state.Set(0, i, val)
		}

		var (
			currentLoss   float64
			elementLosses []float64
		)

		done := false
		episodeReward := 0.0
		maxEpisodeBalance := 1000.0 // Переменная для отслеживания пика прибыли
		for !done {
			var action int

			// Чистый выбор действия ИИ без костылей и подмен
			if rand.Float64() < epsilon {
				action = rand.IntN(ActionDim)
			} else {
				res := policyNet.ForwardDQN(state)
				action = argMax(res.A2, 0)
			}

			// Среда сама обрабатывает кулдаун внутри env.Step и возвращает честную награду/штраф
			reward, isDone := env.Step(action)
			episodeReward += reward
			done = isDone

			nextStateSlice := env.GetStateValues()
			nextState := domain.NewMatrix(1, StateDim)
			for i, val := range nextStateSlice {
				nextState.Set(0, i, val)
			}

			// Новые шаги закидываем с максимальным приоритетом (1.0), чтобы сеть их гарантированно прогнала хотя бы раз
			maxPriority := 1.0

			// Закидываем в SumTree клоны матриц.
			// Теперь им абсолютно плевать на любые сдвиги переменных в цикле!
			treeBuf.Push(Transition{
				State:     state.Clone(),
				Action:    action,
				Reward:    reward,
				NextState: nextState.Clone(),
				Done:      done,
			}, maxPriority)

			state = nextState
			totalSteps++

			if env.Balance > maxEpisodeBalance {
				maxEpisodeBalance = env.Balance // Фиксируем пик
			}

			// Обучение на батчах из SumTree
			// Обучение запускаем строго тогда, когда SumTree полностью заполнится реальным опытом
			if treeBuf.Size >= treeBuf.Capacity {
				batch := make([]Transition, BatchSize)
				indices := make([]int, BatchSize)

				// Сегментируем дерево для выборки батча
				segment := treeBuf.TotalPriority() / float64(BatchSize)

				for i := 0; i < BatchSize; i++ {
					a := segment * float64(i)
					b := segment * float64(i+1)
					s := a + rand.Float64()*(b-a)

					dataIdx, _, transition := treeBuf.Get(s)

					// Подстраховка: если дерево на этапе прогрева вернуло пустой элемент,
					// мы просто берем абсолютно любой случайный РЕАЛЬНЫЙ элемент из уже заполненной части
					if transition.State.Rows() == 0 {
						dataIdx = rand.IntN(treeBuf.Size)
						transition = treeBuf.Data[dataIdx]
					}

					batch[i] = transition
					indices[i] = dataIdx
				}

				// Передаем батч в обучение и получаем лоссы для КАЖДОГО элемента, чтобы обновить дерево
				currentLoss, elementLosses = trainOnBatchWithLosses(&policyNet, &targetNet, batch)
				telemetry.DQNLoss.WithLabelValues(symbol).Set(currentLoss)

				// Обновляем приоритеты в SumTree на основе реального свежего лосса сделок!
				for i := 0; i < BatchSize; i++ {
					// Добавляем микро-константу 1e-5, чтобы приоритет не стал чистым нулем
					newPriority := math.Pow(elementLosses[i]+1e-5, alpha)
					treeBuf.Update(indices[i], newPriority)
				}
			}

			if totalSteps%TargetUpdateFreq == 0 {
				targetNet.CopyFrom(policyNet)
			}
		}

		if epsilon > minEpsilon {
			epsilon *= epsilonDecay
		}
		telemetry.DQNInsideEpsilon.WithLabelValues(symbol).Set(epsilon)

		if episode%50 == 0 || episode == 1 {
			log.Info("Прогресс обучения",
				zap.Int("episode", episode),
				zap.Float64("final_balance", env.Balance),      // Баланс на момент остановки (смерти)
				zap.Float64("peak_balance", maxEpisodeBalance), // Максимальный баланс за эту сессию!
				zap.Float64("epsilon", epsilon),
				zap.Float64("currentLoss", currentLoss),
			)
		}
	}

	// Сохраняем модель на диск
	if err = policyNet.SaveToFile(modelSavePath); err != nil {
		return fmt.Errorf("не удалось сохранить веса: %w", err)
	}

	log.Info("Обучение завершено, модель успешно сохранена", zap.String("path", modelSavePath))
	return nil
}

```
